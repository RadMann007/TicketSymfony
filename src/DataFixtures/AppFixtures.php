<?php

namespace App\DataFixtures;

use App\Entity\Comment;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\TicketPriority;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Données de démonstration pour le développement.
 *
 * Comptes créés (mot de passe : "password") :
 * - admin@test.com (ROLE_ADMIN)
 * - alice@test.com, bob@test.com, claire@test.com, david@test.com (ROLE_USER)
 */
final class AppFixtures extends Fixture
{
    private const PASSWORD = 'password';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $users = $this->loadUsers($manager);
        $this->loadTickets($manager, $users);

        $manager->flush();
    }

    /**
     * @return list<User>
     */
    private function loadUsers(ObjectManager $manager): array
    {
        $accounts = [
            'admin@test.com' => ['ROLE_ADMIN'],
            'alice@test.com' => [],
            'bob@test.com' => [],
            'claire@test.com' => [],
            'david@test.com' => [],
        ];

        $users = [];

        foreach ($accounts as $email => $roles) {
            $user = new User();
            $user->setEmail($email);
            $user->setRoles($roles);
            $user->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));

            $manager->persist($user);
            $users[] = $user;
        }

        return $users;
    }

    /**
     * @param list<User> $users
     */
    private function loadTickets(ObjectManager $manager, array $users): void
    {
        $titles = [
            "Refonte de la page d'accueil",
            "Erreur 500 sur l'export CSV",
            'Ajouter un filtre par statut',
            "Corriger l'alignement du tableau des tickets",
            "Notification email à la création d'un ticket",
            'Mot de passe oublié ne fonctionne pas',
            'Améliorer les performances du tableau de bord',
            "Ajouter la pagination côté API",
            'Le bouton Supprimer reste grisé',
            'Mauvaise redirection après connexion',
            "Documenter les endpoints de l'API",
            'Support du mode sombre sur mobile',
            'Export Excel en plus du CSV',
            'Recherche insensible à la casse',
            "Les rôles ne s'affichent pas en badges",
            "Ticket supprimé par erreur : ajouter une confirmation",
            'Lenteur sur la liste des utilisateurs',
            'Ajouter une page de profil',
            'Changer le mot de passe depuis le profil',
            "Message d'erreur peu explicite sur le login",
            'Trier les tickets par date décroissante',
            'Afficher le nombre de tickets par utilisateur',
            'Le formulaire accepte un titre vide',
            'Ajouter des tests fonctionnels',
            'Corriger les fautes dans les emails',
            'Gérer les sessions expirées',
            "Accès admin à tous les tickets",
            "Améliorer l'accessibilité du menu",
            'Icône manquante sur le bouton Exporter',
            'Nettoyer les dépendances inutilisées',
            "Mettre à jour la documentation d'installation",
            'Ajouter un indicateur de chargement',
            'Le tableau déborde sur petit écran',
            'Uniformiser les couleurs des badges',
            'Ajouter un champ priorité aux tickets',
            'Trier les utilisateurs par email',
            'Empêcher la suppression du dernier admin',
            'Journaliser les actions sensibles',
            "Vérifier les droits avant l'édition",
            'Ajouter une confirmation après création',
            "Les dates s'affichent au format américain",
            'Prévoir un état vide pour le dashboard',
        ];

        $comments = [
            'Reproduit sur Chrome et Firefox.',
            'Voir la capture jointe au ticket.',
            'À planifier pour le prochain sprint.',
            'Le problème vient du contrôleur.',
            'Merci de vérifier en préproduction.',
            'Demandé par plusieurs utilisateurs.',
            'Nécessite une mise à jour de la documentation.',
            'Bloquant pour la prochaine release.',
            'Peut être traité après les vacances.',
            'Ajouter un test de non-régression.',
        ];

        // Rendu reproductible d'une exécution à l'autre.
        mt_srand(20261116);
        shuffle($titles);

        $total = count($titles);
        $tickets = [];

        for ($i = 0; $i < $total; ++$i) {
            $ticket = new Ticket();
            $ticket->setName($titles[$i]);
            $ticket->setComment(mt_rand(1, 4) === 1 ? null : $comments[array_rand($comments)]);

            // Les tickets les plus récents restent ouverts pour rendre le dashboard vivant.
            $ticket->setIsDone($i >= 5 && mt_rand(1, 100) <= 55);

            // Priorités pondérées : surtout des tickets normaux, quelques urgences.
            $priorities = [
                TicketPriority::High,
                TicketPriority::Normal,
                TicketPriority::Normal,
                TicketPriority::Low,
            ];
            $ticket->setPriority($priorities[array_rand($priorities)]);

            $daysAgo = $i < 5 ? $i : mt_rand(5, 89);
            $ticket->setCreatedAt(
                (new DateTimeImmutable('today'))
                    ->modify(sprintf('-%d days', $daysAgo))
                    ->setTime(mt_rand(8, 18), mt_rand(0, 59))
            );

            // Les tickets ouverts ont une échéance, dont une partie déjà dépassée.
            if (!$ticket->isDone()) {
                $dueOffset = mt_rand(1, 100) <= 35 ? -mt_rand(1, 21) : mt_rand(1, 30);
                $ticket->setDueDate((new DateTimeImmutable('today'))->modify(sprintf('%+d days', $dueOffset)));
            } elseif (mt_rand(1, 100) <= 40) {
                $ticket->setDueDate($ticket->getCreatedAt()->modify(sprintf('+%d days', mt_rand(1, 21))));
            }

            $owner = $i < 5 ? $users[$i % count($users)] : $users[array_rand($users)];
            $ticket->setOwner($owner);

            // Environ deux tickets sur trois sont assignés à quelqu'un d'autre que le propriétaire.
            if (mt_rand(1, 100) <= 66) {
                $candidates = array_values(array_filter($users, static fn ($user) => $user !== $owner));
                $ticket->setAssignedTo($candidates[array_rand($candidates)]);
            }

            $manager->persist($ticket);
            $tickets[] = $ticket;
        }

        $this->loadComments($manager, $users, $tickets);
    }

    /**
     * @param list<User>   $users
     * @param list<Ticket> $tickets
     */
    private function loadComments(ObjectManager $manager, array $users, array $tickets): void
    {
        $messages = [
            "Je regarde ça aujourd'hui.",
            'Le problème est reproduit en local.',
            'Peut-on prioriser ce ticket ?',
            "C'est corrigé, en attente de validation.",
            'Vérifié en préproduction, tout est bon.',
            'Il manque encore les tests.',
            'Merci pour le retour.',
            'Je prends le ticket en charge.',
        ];

        $now = new DateTimeImmutable();

        foreach ($tickets as $ticket) {
            if (mt_rand(1, 100) > 60) {
                continue;
            }

            $count = mt_rand(1, 3);

            for ($i = 0; $i < $count; ++$i) {
                $comment = new Comment();
                $comment->setTicket($ticket);
                $comment->setAuthor($users[array_rand($users)]);
                $comment->setContent($messages[array_rand($messages)]);

                $createdAt = $ticket->getCreatedAt()->modify(sprintf('+%d hours', mt_rand(1, 72)));
                $comment->setCreatedAt($createdAt > $now ? $now : $createdAt);

                $manager->persist($comment);
            }
        }
    }
}
