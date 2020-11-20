<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\LanguagePack\Util\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Language\LanguageDefinition;
use Swag\LanguagePack\PackLanguage\PackLanguageDefinition;
use Swag\LanguagePack\SwagLanguagePack;
use Swag\LanguagePack\Util\Exception\MissingLocalesException;

class MigrationHelper
{
    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function createPackLanguageTable(): void
    {
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS `#table#` (
                `id`                    BINARY(16)  NOT NULL,
                `administration_active` TINYINT(1)  NULL DEFAULT '0',
                `storefront_active`     TINYINT(1)  NULL DEFAULT '0',
                `language_id`           BINARY(16)  NOT NULL,
                `created_at`            DATETIME(3) NOT NULL,
                `updated_at`            DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                CONSTRAINT `fk.swag_language_pack_language_language`
                    FOREIGN KEY (`language_id`)
                    REFERENCES `language` (`id`)
                    ON DELETE RESTRICT
                    ON UPDATE CASCADE
            )
            ENGINE=InnoDB
            DEFAULT CHARSET=utf8mb4
            COLLATE=utf8mb4_unicode_ci;
SQL;

        $this->connection->executeUpdate(\str_replace(
            ['#table#'],
            [PackLanguageDefinition::ENTITY_NAME],
            $sql
        ));
    }

    public function alterLanguageAddPackLanguageColumn(): void
    {
        if ($this->languageColumnAlreadyExists()) {
            return;
        }

        $sql = <<<SQL
            ALTER TABLE `#table#`
            ADD COLUMN `#column#` BINARY(16) NULL AFTER `parent_id`,
            ADD CONSTRAINT `fk.language_swag_language_pack_language`
                FOREIGN KEY (`#column#`)
                REFERENCES `#pack_language_table#` (`id`)
                ON DELETE SET NULL
                ON UPDATE CASCADE;
SQL;
        $this->connection->executeUpdate(\str_replace(
            ['#table#', '#column#', '#pack_language_table#'],
            [
                LanguageDefinition::ENTITY_NAME,
                PackLanguageDefinition::PACK_LANGUAGE_FOREIGN_KEY_STORAGE_NAME,
                PackLanguageDefinition::ENTITY_NAME,
            ],
            $sql
        ));
    }

    public function createPackLanguages(): void
    {
        $locales = $this->getLocales();
        $data = $this->createPackLanguageData($locales);

        $packLanguages = [];
        $languages = [];
        foreach ($data as $locale) {
            $packLanguage = [
                'id' => Uuid::randomBytes(),
                'languageId' => $locale['languageId'],
            ];

            if ($locale['languageId'] === null) {
                $newLanguageId = Uuid::randomBytes();
                $packLanguage['languageId'] = $newLanguageId;

                $languages[] = [
                    'id' => $newLanguageId,
                    'name' => $locale['name'],
                    'localeId' => $locale['id'],
                    'translationCodeId' => $locale['id'],
                ];
            }

            $packLanguages[] = $packLanguage;
        }

        $insertLanguagesSql = <<<SQL
            INSERT INTO `language` (`id`, `name`, `locale_id`, `translation_code_id`, `created_at`)
            VALUES (:id, :name, :localeId, :translationCodeId, NOW());
SQL;

        foreach ($languages as $language) {
            $this->connection->executeUpdate($insertLanguagesSql, $language);
        }

        $insertPackLanguagesSql = <<<SQL
            DELETE FROM `swag_language_pack_language`
            WHERE `language_id` = :languageId;

            INSERT INTO `swag_language_pack_language` (`id`, `language_id`, `created_at`)
            VALUES (:id, :languageId, NOW());
            
            UPDATE `language`
            SET swag_language_pack_language_id = :id
            WHERE `id` = :languageId;
SQL;

        foreach ($packLanguages as $packLanguage) {
            $this->connection->executeUpdate($insertPackLanguagesSql, $packLanguage);
        }
    }

    private function languageColumnAlreadyExists(): bool
    {
        $sql = <<<SQL
            SHOW COLUMNS FROM `#table#`
            LIKE '#column#';
SQL;

        $result = $this->connection->executeQuery(\str_replace(
            ['#table#', '#column#'],
            [
                LanguageDefinition::ENTITY_NAME,
                PackLanguageDefinition::PACK_LANGUAGE_FOREIGN_KEY_STORAGE_NAME,
            ],
            $sql
        ));

        return (bool) $result->fetch();
    }

    private function getLocales(): array
    {
        $sql = <<<SQL
            SELECT `id`, `code`
            FROM `locale`
            WHERE `code` IN (?);
SQL;

        $locales = $this->connection->executeQuery(
            $sql,
            [\array_values(SwagLanguagePack::SUPPORTED_LANGUAGES)],
            [Connection::PARAM_STR_ARRAY]
        )->fetchAll();

        if (\count(SwagLanguagePack::SUPPORTED_LANGUAGES) !== \count($locales)) {
            throw new MissingLocalesException($this->getMissingLocales($locales));
        }

        $enhancedLocales = [];
        foreach (SwagLanguagePack::SUPPORTED_LANGUAGES as $name => $code) {
            foreach ($locales as $locale) {
                if ($code === $locale['code']) {
                    $locale['name'] = $name;
                    $enhancedLocales[$code] = $locale;
                }
            }
        }

        return $enhancedLocales;
    }

    private function getMissingLocales(array $locales): array
    {
        return \array_diff(
            SwagLanguagePack::SUPPORTED_LANGUAGES,
            \array_column($locales, 'code')
        );
    }

    private function createPackLanguageData(array $locales): array
    {
        $sql = <<<SQL
            SELECT lang.`id` as id, loc.`code` as code
            FROM `language` lang
            LEFT JOIN `locale` loc ON loc.`id` = lang.`locale_id`
            WHERE loc.`code` IN (?)
SQL;

        $existingLanguages = $this->connection->executeQuery(
            $sql,
            [\array_keys($locales)],
            [Connection::PARAM_STR_ARRAY]
        )->fetchAll();

        return \array_map(function ($locale) use ($existingLanguages): array {
            $languageId = null;
            foreach ($existingLanguages as $language) {
                if ($locale['code'] === $language['code']) {
                    $languageId = $language['id'];

                    break;
                }
            }
            $locale['languageId'] = $languageId;

            return $locale;
        }, $locales);
    }
}
