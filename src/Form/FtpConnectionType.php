<?php // src/Form/FtpConnectionType.php
namespace App\Form;

use App\Entity\FtpConnection;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class FtpConnectionType extends AbstractType
{
	public function buildForm(FormBuilderInterface $b, array $opts): void
	{
		$b
			->add('host', TextType::class)
			->add('port', IntegerType::class)
			->add('username', TextType::class)
			->add('password', PasswordType::class, [
				'mapped' => false,
				'required' => false,
				'help' => 'Laisse vide pour conserver le mot de passe actuel.',
			])
			->add('secure', CheckboxType::class, ['required' => false, 'label' => 'FTPS (TLS)'])
			->add('remoteDir', TextType::class, ['required' => false])
			->add('timeoutMs', IntegerType::class)
			->add('csvName', TextType::class);
	}

	public function configureOptions(OptionsResolver $r): void
	{
		$r->setDefaults([
			'data_class' => FtpConnection::class,
			'csrf_protection' => true,
			'csrf_field_name' => '_token',
			'csrf_token_id' => 'ftp_connection',
		]);
	}
}
