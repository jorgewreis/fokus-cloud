<?php

namespace App\Http\Middleware;

use App\Support\Deployment\ReleaseIdentity;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class DeploymentIdentity
{
    public function handle(Request $request, Closure $next)
    {
        // Match the pre-bootstrap boundary in public/index.php exactly, including
        // outer slashes, without Symfony base-URL or URL-parser reinterpretation.
        $requestPath = explode('?', $request->server('REQUEST_URI', ''), 2)[0];
        if (trim($requestPath, '/') !== '_deployment/release') {
            return $next($request);
        }

        $probe = $request->query('deploy_probe');
        if (! $request->isMethod('GET') || ! is_string($probe)
            || preg_match('/\A[0-9a-f]{48}\z/D', $probe) !== 1
            || count($request->query()) !== 1) {
            return $this->response('invalid_probe', null, null, 400);
        }

        try {
            $identity = defined('FOKUS_RELEASE_IDENTITY') ? FOKUS_RELEASE_IDENTITY : [];
            $release = (new ReleaseIdentity(is_array($identity) ? $identity : [], base_path()))->verifiedRelease();

            return $this->response('ok', $release, $probe, 200);
        } catch (Throwable) {
            return $this->response('unavailable', null, null, 503);
        }
    }

    private function response(string $status, ?string $release, ?string $probe, int $code): JsonResponse
    {
        return new JsonResponse(compact('status', 'release', 'probe'), $code, [
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
