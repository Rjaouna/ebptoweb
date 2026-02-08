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
		$admin = new User();
		$admin->setEmail('admin@gmail.com');
		$admin->setRoles(['ROLE_ADMIN']);

		$hashedPassword = $this->passwordHasher->hashPassword($admin, 'admin123');
		$admin->setPassword($hashedPassword);

		$manager->persist($admin);
		$manager->flush();
	}
}
