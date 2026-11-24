<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class AttachmentStorage
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/uploads/attachments')]
        private string $directory,
    ) {
    }

    public function store(UploadedFile $file): string
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . ($file->guessExtension() ?: 'bin');
        $file->move($this->directory, $storedName);

        return $storedName;
    }

    public function path(string $storedName): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . basename($storedName);
    }

    public function delete(string $storedName): void
    {
        $path = $this->path($storedName);

        if (is_file($path)) {
            unlink($path);
        }
    }
}
