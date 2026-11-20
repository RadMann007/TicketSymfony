<?php

namespace App\Dto;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données de téléversement d'une pièce jointe, validées avant écriture en base.
 */
final class AttachmentUploadData
{
    #[Assert\NotNull(message: 'Veuillez sélectionner un fichier.')]
    #[Assert\File(
        maxSize: '5M',
        mimeTypes: [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
            'application/zip',
            'application/x-zip-compressed',
        ],
        maxSizeMessage: 'Le fichier est trop volumineux ({{ size }} {{ suffix }}). Taille maximale : {{ limit }} {{ suffix }}.',
        mimeTypesMessage: 'Type de fichier non autorisé ({{ type }}).',
        uploadIniSizeErrorMessage: 'Le fichier est trop volumineux. Taille maximale autorisée par le serveur : {{ limit }} {{ suffix }}.',
        uploadFormSizeErrorMessage: 'Le fichier est trop volumineux.',
        uploadPartialErrorMessage: 'Le fichier n\'a été que partiellement téléversé.',
        uploadNoFileErrorMessage: 'Aucun fichier n\'a été téléversé.',
        uploadNoTmpDirErrorMessage: 'Dossier temporaire non configuré sur le serveur.',
        uploadCantWriteErrorMessage: 'Impossible d\'écrire le fichier temporaire sur le disque.',
        uploadExtensionErrorMessage: 'Une extension PHP a bloqué le téléversement.',
        uploadErrorMessage: 'Le fichier n\'a pas pu être téléversé.',
        disallowEmptyMessage: 'Le fichier est vide.',
    )]
    public ?UploadedFile $file = null;
}
