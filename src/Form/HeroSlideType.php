<?php

namespace App\Form;

use App\Entity\HeroSlide;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\OptionsResolver\OptionsResolver;

class HeroSlideType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['required' => false, 'label' => 'Titre'])
            ->add('subtitle', TextareaType::class, ['required' => false, 'label' => 'Sous-titre'])
            ->add('primaryLabel', TextType::class, ['required' => false, 'label' => 'Bouton 1 - Texte'])
            ->add('primaryUrl', TextType::class, ['required' => false, 'label' => 'Bouton 1 - Lien'])
            ->add('secondaryLabel', TextType::class, ['required' => false, 'label' => 'Bouton 2 - Texte'])
            ->add('secondaryUrl', TextType::class, ['required' => false, 'label' => 'Bouton 2 - Lien'])
            ->add('enabled', CheckboxType::class, ['required' => false, 'label' => 'Actif'])

            // champ upload (non mappé)
            ->add('imageFile', FileType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Image (JPG/PNG/WebP)',
                'constraints' => [
                    new File([
                        'maxSize' => '5M',
                        'mimeTypes' => [
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                        ],
                        'mimeTypesMessage' => 'Image invalide (JPG/PNG/WebP).',
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => HeroSlide::class,
        ]);
    }
}
