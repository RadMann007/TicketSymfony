<?php

namespace App\Form;

use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TicketType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom du ticket',
                'attr' => ['placeholder' => 'Ordi en panne...']
            ])
            ->add('isDone', CheckboxType::class, [
                'label' => 'Terminé ?',
                'required' => false,
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Commentaire'
            ])
        ;

        if ($options['allow_assignment']) {
            $builder->add('assignedTo', EntityType::class, [
                'class' => User::class,
                'label' => 'Assigné à',
                'required' => false,
                'placeholder' => 'Non assigné',
                'choice_label' => 'email',
                'query_builder' => static fn (UserRepository $repository) => $repository
                    ->createQueryBuilder('u')
                    ->orderBy('u.email', 'ASC'),
            ]);
        }

        $builder->add('save', SubmitType::class, [
            'label' => 'Créer le ticket'
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ticket::class,
            'allow_assignment' => false,
        ]);

        $resolver->setAllowedTypes('allow_assignment', 'bool');
    }
}
