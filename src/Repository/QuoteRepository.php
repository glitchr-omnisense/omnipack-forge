<?php

namespace Base\Forge\Repository;

use Base\Forge\Entity\Quote;
use Base\Marketplace\Quote\QuoteRepositoryTrait;
use Base\Database\Repository\ServiceEntityRepository;

// Declared only beside omnipack/marketplace: a missing trait is a fatal error, not a class that
// fails to load (omnibase's warm-up loads every class of a bundle, and the forge stands without it).
if (!trait_exists(QuoteRepositoryTrait::class)) {
    return;
}

/**
 * The studio's quotes, numbered Q-<year>-<n>: what every quote table answers
 * is omnibase/marketplace's (QuoteRepositoryTrait: saveNumbered(),
 * findForClient(), pipeline()).
 *
 * @method Quote|null find($id, $lockMode = null, $lockVersion = null)
 * @method Quote|null findOneBy(array $criteria, array $orderBy = null)
 * @method Quote[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class QuoteRepository extends ServiceEntityRepository
{
    use QuoteRepositoryTrait;
}
