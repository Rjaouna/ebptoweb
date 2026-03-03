<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {}

    public function load(ObjectManager $manager): void
    {
        // ADMIN
        $admin = $this->makeUser(
            email: 'jaouna.ridouane@gmail.com',
            roles: ['ROLE_ADMIN'],
            plainPassword: 'jaounaridouane'
        );
        $manager->persist($admin);

        // USER NORMAL
        $user = $this->makeUser(
            email: 'user@hopic.ma',
            roles: ['ROLE_USER'],
            plainPassword: 'user12345'
        );
        $manager->persist($user);

        // PREPARATEUR
        $preparateur = $this->makeUser(
            email: 'preparateur@hopic.ma',
            roles: ['ROLE_PREPARATEUR'],
            plainPassword: 'prep12345'
        );
        $manager->persist($preparateur);

        $manager->flush();
    }

    private function makeUser(string $email, array $roles, string $plainPassword): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles($roles);

        // Si ton User a un champ "statut" (bool), on l’active sans casser si la méthode n’existe pas
        if (method_exists($u, 'setStatut')) {
            $u->setStatut(true);
        }

        $u->setPassword($this->passwordHasher->hashPassword($u, $plainPassword));

        return $u;
    }
}