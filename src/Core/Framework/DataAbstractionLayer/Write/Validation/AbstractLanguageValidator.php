<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\LanguagePack\Core\Framework\DataAbstractionLayer\Write\Validation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\ResultStatement;
use Doctrine\DBAL\FetchMode;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PostWriteValidationEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Swag\LanguagePack\PackLanguage\PackLanguageDefinition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

abstract class AbstractLanguageValidator implements EventSubscriberInterface
{
    /**
     * @var Connection
     */
    protected $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PostWriteValidationEvent::class => 'postValidate',
        ];
    }

    public function postValidate(PostWriteValidationEvent $event): void
    {
        $violationList = new ConstraintViolationList();
        foreach ($event->getCommands() as $command) {
            if (!($command instanceof InsertCommand || $command instanceof UpdateCommand)
                || $command->getDefinition()->getClass() !== $this->getSupportedCommandDefinitionClass()
            ) {
                continue;
            }

            $this->validate($command, $violationList);
        }

        if ($violationList->count() > 0) {
            $event->getExceptions()->add(new WriteConstraintViolationException($violationList));
        }
    }

    abstract protected function getSupportedCommandDefinitionClass(): string;

    protected function validate(WriteCommand $command, ConstraintViolationList $violationList): void
    {
        $payload = $command->getPayload();
        if (!isset($payload['language_id']) || $this->isStorefrontLanguageAvailable($payload['language_id'])) {
            return;
        }

        $violationList->add(
            new ConstraintViolation(
                \sprintf('The language with the id "%s" is disabled for all Sales Channels.', Uuid::fromBytesToHex($payload['language_id'])),
                'The language with the id "{{ languageId }}" is disabled for all Sales Channels.',
                [$payload['language_id']],
                null,
                $command->getPath(),
                $payload['language_id']
            )
        );
    }

    protected function isStorefrontLanguageAvailable(string $languageId): bool
    {
        $statement = $this->connection->createQueryBuilder()
            ->select('storefront_active')
            ->from(PackLanguageDefinition::ENTITY_NAME)
            ->where('language_id = :languageId')
            ->setParameter('languageId', $languageId)
            ->setMaxResults(1)
            ->execute();

        if (!$statement instanceof ResultStatement) {
            return false;
        }

        return (bool) $statement->fetch(FetchMode::COLUMN);
    }
}
