<?php

namespace Base\Forge\Twig;

use Base\Forge\Entity\Release;
use Base\Forge\Entity\TimeEntry;
use Base\Forge\Service\ReleaseDocs;
use Base\Service\TradingInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * {{ 210|forge_hours }} → 3h30; {{ 2500|forge_money('EUR') }} → 25,00 €;
 * {{ 2500|forge_price('EUR') }} → 25,00 € ≈ 27,30 $US for a visitor who reads
 * dollars (base-bundle's Trading rendered currency, with its rate).
 * {% if forge_feature('marketplace') %}: whether a feature is on (Base\Forge\Feature);
 * {% if forge_release_docs(release) %}: whether a release has its documentation.
 */
final class ForgeExtension
{
    /** @param list<string> $features */
    public function __construct(
        private readonly ?TradingInterface $trading = null,
        private readonly ?ReleaseDocs $docs = null,
        #[Autowire('%forge.enabled_features%')] private readonly array $features = [],
    ) {
    }

    #[AsTwigFunction('forge_feature')]
    public function feature(string $name): bool
    {
        return \in_array($name, $this->features, true);
    }

    #[AsTwigFunction('forge_release_docs')]
    public function releaseDocs(Release $release): bool
    {
        return (bool) $this->docs?->has($release);
    }

    #[AsTwigFilter('forge_hours')]
    public function hours(?int $minutes): string
    {
        return TimeEntry::formatMinutes((int) $minutes);
    }

    #[AsTwigFilter('forge_money')]
    public function money(?int $cents, string $currency = 'EUR', ?string $locale = null): string
    {
        $formatter = new \NumberFormatter($locale ?? \Locale::getDefault(), \NumberFormatter::CURRENCY);

        return (string) $formatter->formatCurrency(((int) $cents) / 100, $currency);
    }

    /**
     * A price as it is charged, then what it comes to in the visitor's
     * currency when that is another and a rate is known: indicative only,
     * the payment stays in the price's own currency.
     */
    #[AsTwigFilter('forge_price', isSafe: ['html'])]
    public function price(?int $cents, string $currency = 'EUR', ?string $locale = null): string
    {
        $price = htmlspecialchars($this->money($cents, $currency, $locale));

        $target = $this->trading?->getRenderedCurrency();
        $rate = $target && $target !== $currency ? $this->trading->getFallback($currency, $target)?->getValue() : null;
        if (!$rate) {
            return $price;
        }

        return $price.' <small class="forge-approx">≈ '.htmlspecialchars($this->money((int) round((int) $cents * $rate), $target, $locale)).'</small>';
    }
}
