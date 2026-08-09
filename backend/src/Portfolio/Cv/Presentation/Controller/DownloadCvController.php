<?php

declare(strict_types=1);

namespace App\Portfolio\Cv\Presentation\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Protégé par l'access_control "^/api/cv" (config/packages/security.yaml) :
 * n'est jamais atteint sans un JWT valide, sur le même modèle que
 * App\Security\User\Presentation\Controller\CurrentUserController. Le fichier
 * réel ne vit jamais dans le dépôt Git ni dans l'image Docker (voir
 * backend/resources/README.md) : $cvFilePath pointe vers un chemin déposé
 * localement ou monté par l'orchestrateur en environnement déployé.
 */
final class DownloadCvController
{
    public function __construct(
        #[Autowire(param: 'app.cv_file_path')]
        private readonly string $cvFilePath,
        #[Autowire(param: 'app.cv_download_filename')]
        private readonly string $cvDownloadFilename,
    ) {
    }

    #[Route('/api/cv', name: 'api_cv_download', methods: ['GET'])]
    public function __invoke(): BinaryFileResponse
    {
        if (!is_file($this->cvFilePath)) {
            throw new NotFoundHttpException('CV non disponible.');
        }

        $response = new BinaryFileResponse($this->cvFilePath);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->cvDownloadFilename);

        return $response;
    }
}
