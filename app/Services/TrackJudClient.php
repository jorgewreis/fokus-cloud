<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TrackJudClient
{
    public function configured(): bool
    {
        return trim((string) config('services.trackjud.api_key')) !== '';
    }

    public function balance(): Response
    {
        return $this->request()->get($this->url('/balance'));
    }

    public function courts(): Response
    {
        return $this->request()->get($this->url('/courts'));
    }

    public function createConsult(string $document, array $courts): Response
    {
        return $this->request()->post($this->url('/consults'), [
            'document' => $document,
            'courts' => array_values(array_unique($courts)),
        ]);
    }

    public function consult(string $id): Response
    {
        return $this->request()->get($this->url('/consults/'.rawurlencode($id)));
    }

    private function request()
    {
        return Http::acceptJson()
            ->withToken((string) config('services.trackjud.api_key'))
            ->connectTimeout(5)
            ->timeout((int) config('services.trackjud.timeout', 20));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.trackjud.base_url'), '/').$path;
    }
}
