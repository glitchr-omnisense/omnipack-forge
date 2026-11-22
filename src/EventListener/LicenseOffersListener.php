<?php

namespace Base\Forge\EventListener;

use Base\Contract\Lifecycle\Listeners;
use Base\Contract\Lifecycle\PostLoad;
use Base\Database\Repository\Repositories;
use Base\Forge\Entity\Product\LicenseOffer;
use Base\Forge\Entity\Software;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A software read from the database learns where its licence offers are: the marketplace's
 * products naming it, read when Software::getOffers() is first called. The marketplace
 * feature's (Base\Forge\Feature): without it a software has no offers.
 */
#[AutoconfigureTag(Listeners::TAG)]
final class LicenseOffersListener
{
    public function __construct(private readonly Repositories $repositories)
    {
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof PostLoad || !$event->entity instanceof Software) {
            return;
        }

        $software = $event->entity;
        $repositories = $this->repositories;
        $software->loadOffersWith(static fn (): array => $repositories->of(LicenseOffer::class)->findBy(['software' => $software]));
    }
}
