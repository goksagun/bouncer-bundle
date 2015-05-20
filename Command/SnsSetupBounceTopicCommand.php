<?php
namespace Shivas\BouncerBundle\Command;

use Shivas\BouncerBundle\Compatibility\ChoiceQuestion;
use Shivas\BouncerBundle\Compatibility\QuestionHelper;
use Shivas\BouncerBundle\Model\Topic;
use Shivas\BouncerBundle\Model\TopicRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\RouterInterface;

class SnsSetupBounceTopicCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this->setDescription(
            'Registers SNS Topic, attaches it to chosen identities as bounce topic and subscribes endpoint to receive bounce notifications'
        );
        $this->setName('swiftmailer:sns:setup-bounce-topic');
        $this->addArgument('name', InputArgument::REQUIRED, 'Topic name to create, follows AWS naming rules');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();
        $context = $container->get('router')->getContext();
        $context->setHost($container->getParameter('shivas_bouncer.bounce_endpoint')['host']);
        $context->setScheme($container->getParameter('shivas_bouncer.bounce_endpoint')['protocol']);

        $apiFactory = $container->get('shivas_bouncer.aws.client.factory');

        $sesClient = $apiFactory->getSesClient();
        $snsClient = $apiFactory->getSnsClient();

        // fetch identities
        $response = $sesClient->listIdentities();
        $identities = $response->get('Identities');

        $helperSet = $this->getHelperSet();
        if (!$helperSet->has('question')) {
            $helperSet->set(new QuestionHelper(), 'question');
        }

        $helper = $this->getHelper('question');
        $question = new ChoiceQuestion(
            'Please select identities to hook to: (comma separated numbers, default: all)',
            $identities,
            implode(",", range(0, count($identities) - 1, 1))
        );

        $question->setMultiselect(true);
        $selectedIdentities = $helper->ask($input, $output, $question);

        // create SNS topic
        $topic = array('Name' => $input->getArgument('name'));
        $response = $snsClient->createTopic($topic);
        $topicArn = $response->get('TopicArn');

        /** @var TopicRepositoryInterface $topicRepo */
        $topicRepo = $container->get('shivas_bouncer.object_manager')->getRepository('ShivasBouncerBundle:Topic');
        $topicRepo->save(new Topic($topicArn));

        $output->writeln("\nTopic created: " . $topicArn . "\n");

        // subscribe selected SES identities to SNS topic
        $output->writeln(sprintf('Registering <comment>"%s"</comment> topic for identities:', $topic['Name']));
        foreach ($selectedIdentities as $identity) {
            $output->write($identity . ' ... ');
            $sesClient->setIdentityNotificationTopic(
                array(
                    'Identity' => $identity,
                    'NotificationType' => 'Bounce',
                    'SnsTopic' => $topicArn
                )
            );
            $output->writeln('OK');
        }

        $subscribe = [
            'TopicArn' => $topicArn,
            'Protocol' => $container->getParameter('shivas_bouncer.bounce_endpoint')['protocol'],
            'Endpoint' => $this->getContainer()
                ->get('router')
                ->generate(
                    $container->getParameter(
                        'shivas_bouncer.bounce_endpoint'
                    )['route_name'],
                    array(),
                    RouterInterface::ABSOLUTE_URL
                )
        ];

        $response = $snsClient->subscribe($subscribe);

        $output->writeln(sprintf("\nSubscription endpoint URI: <comment>%s</comment>\n", $subscribe['Endpoint']));
        $output->writeln(sprintf("Subscription status: <comment>%s</comment>", $response->get('SubscriptionArn')));
    }
}
