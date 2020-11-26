<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\LanguagePack\Test\Helper;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Swag\LanguagePack\PackLanguage\PackLanguageDefinition;
use Swag\LanguagePack\PackLanguage\PackLanguageEntity;

trait ServicesTrait
{
    use IntegrationTestBehaviour;

    private function setStorefrontActiveForLanguageByName(string $name, bool $storefrontActive, Context $context): string
    {
        /** @var EntityRepositoryInterface $packLanguageRepository */
        $packLanguageRepository = $this->getContainer()->get(\sprintf('%s.repository', PackLanguageDefinition::ENTITY_NAME));

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('language.name', $name));

        /** @var PackLanguageEntity|null $first */
        $first = $packLanguageRepository->search($criteria, $context)->first();
        static::assertNotNull($first);

        $packLanguageRepository->update([
            [
                'id' => $first->getId(),
                'storefrontActive' => $storefrontActive,
            ],
        ], $context);

        return $first->getLanguageId();
    }

    private function setStorefrontActiveForLanguageByLocale(string $locale, bool $storefrontActive, Context $context): string
    {
        /** @var EntityRepositoryInterface $packLanguageRepository */
        $packLanguageRepository = $this->getContainer()->get(\sprintf('%s.repository', PackLanguageDefinition::ENTITY_NAME));

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('language.locale.code', $locale));

        /** @var PackLanguageEntity|null $first */
        $first = $packLanguageRepository->search($criteria, $context)->first();
        static::assertNotNull($first);

        $packLanguageRepository->update([
            [
                'id' => $first->getId(),
                'storefrontActive' => $storefrontActive,
            ],
        ], $context);

        return $first->getLanguage()->getLocaleId();
    }
}
