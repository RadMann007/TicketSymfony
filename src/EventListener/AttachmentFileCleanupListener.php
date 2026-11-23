<?php

namespace App\EventListener;

use App\Entity\Attachment;
use App\Service\AttachmentStorage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Events;

/**
 * Supprime le fichier sur le disque quand l'entité Attachment est retirée.
 *
 * Couvre la suppression directe de la pièce jointe comme la suppression en
 * cascade du ticket auquel elle est rattachée.
 */
#[AsEntityListener(event: Events::postRemove, entity: Attachment::class)]
final readonly class AttachmentFileCleanupListener
{
    public function __construct(
        private AttachmentStorage $storage,
    ) {
    }

    public function postRemove(Attachment $attachment, PostRemoveEventArgs $args): void
    {
        $this->storage->delete($attachment->getStoredName());
    }
}
