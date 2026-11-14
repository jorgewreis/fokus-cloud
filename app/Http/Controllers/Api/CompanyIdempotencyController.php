<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompanyIdempotencyFactory;
use App\Services\PlatformAudit;
use App\Services\PlatformSessionBinding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** Routes remain blocked by a separate unconditional guard. */
final class CompanyIdempotencyController extends Controller
{
    public function issue(Request $request): JsonResponse
    {
        $data = $this->operation($request);
        $binding = $this->binding($request);
        $settings = $this->settings($binding);
        $token = $settings['store']->issue((string) $request->user()->id, $data, $settings['lifetime_seconds']);
        $token = $settings['session_keys']->bindSession($token, $binding);

        return response()->json(['message' => 'Chave emitida.'], 201)
            ->header('Idempotency-Key', $token)->header('Cache-Control', 'private, no-store');
    }

    public function status(Request $request): JsonResponse
    {
        $operation = $this->operation($request);
        $token = $this->token($request);
        $binding = $this->binding($request);
        $settings = $this->settings($binding);
        try {
            $token = $settings['session_keys']->unbindSession($token, $binding);
            $result = $settings['store']->status($token, (string) $request->user()->id, $operation, now()->getTimestamp());
        } catch (InvalidArgumentException) {
            abort(422, 'Chave de idempotência inválida.');
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $request, PlatformAudit $audit): JsonResponse
    {
        $operation = $this->operation($request);
        $token = $this->token($request);
        $binding = $this->binding($request);
        $actor = (string) $request->user()->id;
        $settings = $this->settings($binding);
        try {
            $token = $settings['session_keys']->unbindSession($token, $binding);
            $result = $settings['store']->revoke($token, $actor, $operation, now()->getTimestamp(), function () use ($audit, $actor, $operation, $request): void {
                $audit->record($actor, 'backoffice.company_idempotency_revoked', 'company_idempotency', metadata: ['operation' => $operation], request: $request);
            });
        } catch (InvalidArgumentException) {
            abort(422, 'Chave de idempotência inválida.');
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    private function operation(Request $request): string
    {
        $data = $request->validate(['operation' => ['required', 'string', Rule::in(['company.create', 'company.update'])]]);

        return $data['operation'];
    }

    private function token(Request $request): string
    {
        $values = $request->headers->all('Idempotency-Key');
        abort_unless(count($values) === 1 && is_string($values[0]) && $values[0] !== '' && strlen($values[0]) <= 256, 422, 'Chave de idempotência inválida.');

        return $values[0];
    }

    private function settings(string $binding): array
    {
        try {
            return (new CompanyIdempotencyFactory)->build((array) config('company_idempotency', []), $binding);
        } catch (InvalidArgumentException) {
            abort(503, 'Solicitação indisponível.');
        }
    }

    private function binding(Request $request): string
    {
        $binding = (new PlatformSessionBinding)->current($request->session());
        abort_unless($binding !== null, 503, 'Sessão indisponível.');

        return $binding;
    }
}
