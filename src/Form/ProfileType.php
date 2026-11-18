<?php

namespace App\Form;

use App\Dto\ProfileData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'empty_data' => '',
            ])
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'required' => false,
                'help' => 'Requis pour modifier l\'email ou le mot de passe.',
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'required' => false,
                'first_options' => [
                    'label' => 'Nouveau mot de passe',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Confirmer le mot de passe',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Enregistrer',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfileData::class,
            'original_email' => null,
            'validation_groups' => static function (FormInterface $form): array {
                /** @var ProfileData $data */
                $data = $form->getData();

                $passwordChanged = '' !== (string) $data->plainPassword;
                $emailChanged = $data->email !== $form->getConfig()->getOption('original_email');

                return ($passwordChanged || $emailChanged) ? ['Default', 'current_password'] : ['Default'];
            },
        ]);

        $resolver->setAllowedTypes('original_email', ['null', 'string']);
    }
}
