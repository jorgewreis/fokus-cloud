<?php

namespace App\Support;

use App\Services\CatalogManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PublishedLawOffers
{
    private const PLACEHOLDER = '<div id="lp-offers" class="lp-offers" aria-live="polite"><p class="lp-state">Consultando as ofertas publicadas…</p></div>';

    public function inject(string $html): string
    {
        if (! str_contains($html, self::PLACEHOLDER)) {
            return $html;
        }

        try {
            $catalog = $this->publishedCatalog();
        } catch (QueryException) {
            return $html;
        }

        if ($catalog === null) {
            return $html;
        }

        return str_replace(
            self::PLACEHOLDER,
            '<div id="lp-offers" class="lp-offers" aria-live="polite">'.$this->cards($catalog['plans'] ?? []).'</div>',
            $html,
        );
    }

    /** @param array<int, array<string, mixed>> $plans */
    public function cards(array $plans): string
    {
        $cards = '';
        $ordered = $plans;
        usort($ordered, function (array $left, array $right): int {
            $rank = function (array $plan): int {
                $name = (string) ($plan['name'] ?? '').' '.(string) ($plan['base_name'] ?? '');
                $segment = (string) ($plan['segment'] ?? '');
                if ($segment === 'advocacia') {
                    return 0;
                }
                if (preg_match('/geral|órgão|orgao/iu', $name)) {
                    return 1;
                }
                if (preg_match('/judicial|judici/iu', $name)) {
                    return 2;
                }

                return $segment === 'setor_publico' ? 3 : 4;
            };

            return $rank($left) <=> $rank($right)
                ?: ((int) ($left['display_order'] ?? 0)) <=> ((int) ($right['display_order'] ?? 0));
        });
        foreach ($ordered as $plan) {
            $featured = ! empty($plan['featured']);
            $segment = (string) ($plan['segment'] ?? '');
            $segmentClass = match ($segment) {
                'advocacia' => 'advocacia',
                'setor_publico' => 'setor-publico',
                default => 'default',
            };
            $segmentLabel = match ($segment) {
                'advocacia' => 'ADVOCACIA',
                'setor_publico' => 'SETOR PÚBLICO',
                default => $segment !== '' ? $segment : 'FOKUS LAW',
            };
            $description = trim((string) ($plan['description'] ?? ''));
            if ($description === '') {
                $description = 'Composição de módulos para sua operação.';
            }
            $cards .= '<article class="lp-offer'.($featured ? ' lp-offer-featured' : '').'">'
                .($featured ? '<span class="lp-recommended-badge">Recomendado</span>' : '')
                .'<p class="lp-segment-tag lp-segment-tag--'.$segmentClass.'">'.$this->escape($segmentLabel).'</p>'
                .'<h3>'.$this->escape((string) ($plan['name'] ?? '')).'</h3>'
                .'<p>'.$this->escape($description).'</p>'
                .'<strong>'.self::formatMoney($plan['monthly_amount'] ?? 0).' <small>/ mês</small></strong>'
                .'<button class="fs-btn fs-btn-primary law-submit" type="button" data-choose-plan="'.$this->escape((string) ($plan['code'] ?? '')).'">'.($segment === 'setor_publico' ? 'Assinar' : 'Configurar plano').'</button>'
                .'</article>';
        }

        return '<article class="lp-offer lp-standalone"><p class="law-eyebrow law-eyebrow-sage">SEM PLANO-BASE</p><h3>Módulos avulsos</h3><p>Escolha só os módulos disponíveis que você vai usar. Sem plano, cada módulo é cobrado pelo preço cheio do catálogo.</p><button class="fs-btn fs-btn-primary law-submit" type="button" data-choose-custom>Escolher módulos</button></article>'.$cards;
    }

    public static function formatMoney(mixed $value): string
    {
        $amount = is_numeric($value) ? (float) $value : 0.0;

        return "R$\u{00A0}".number_format($amount, 2, ',', '.');
    }

    /** @return array<string, mixed>|null */
    private function publishedCatalog(): ?array
    {
        foreach (['fokus-law', 'law'] as $code) {
            if (! DB::table('products')->where('code', $code)->exists()) {
                continue;
            }

            try {
                return app(CatalogManager::class)->publicCatalog($code);
            } catch (HttpExceptionInterface $exception) {
                if (! in_array($exception->getStatusCode(), [404, 422, 503], true)) {
                    throw $exception;
                }
            }
        }

        return null;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
