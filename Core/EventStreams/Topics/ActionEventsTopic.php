<?php
/**
 * All action events are submitted via this producer
 */
namespace Minds\Core\EventStreams\Topics;

use Minds\Core\EventStreams\ActionEvent;
use Minds\Core\EventStreams\EventInterface;
use Minds\Entities\User;
use Minds\Entities\Entity;
use Pulsar\MessageBuilder;
use Pulsar\ProducerConfiguration;
use Pulsar\ConsumerConfiguration;
use Pulsar\Consumer;
use Pulsar\SchemaType;
use Pulsar\Result;

class ActionEventsTopic extends AbstractTopic implements TopicInterface
{
    /**
     * Sends action events to our stream
     * @param EventInterface $event
     */
    public function send(EventInterface $event): bool
    {
        if (!$event instanceof ActionEvent) {
            return false;
        }

        $tenant = $this->getPulsarTenant();
        $namespace = $this->getPulsarNamespace();
        $topic = 'event-action-' . $event->getAction();

        // Build the config and include the schema

        $config = new ProducerConfiguration();
        $config->setSchema(SchemaType::AVRO, "action", $this->getSchema(), []);

        $producer = $this->client()->createProducer("persistent://$tenant/$namespace/$topic", $config);

        // Build the message

        $builder = new MessageBuilder();
        $message = $builder
            //->setPartitionKey(0)
            ->setContent(json_encode([
                'action' => $event->getAction(),
                'action_data' => $event->getActionData(),
                'user_guid' => (string) $event->getUser()->getGuid(),
                'entity_urn' => (string) $event->getEntity()->getUrn(),
                'entity_guid' => (string) $event->getEntity()->getGuid(),
                'entity_owner_guid' => (string) $event->getEntity()->getOwnerGuid(),
                'entity_type' => (string) $event->getEntity()->getType(),
                'entity_subtype' => (string) $event->getEntity()->getSubtype(),
            ]))
            ->build();

        // Send the event to the stream

        $result = $producer->send($message);

        if ($result != Result::ResultOk) {
            return false;
        }

        return true;
    }

    /**
     * Consume stream events. Use a new $subscriptionId per service
     * eg. notifications, analytics, recomendations
     * @param string $subscriptionId
     * @param callable $callback - the logic for the event
     * @param string $topicRegex - defaults to * (all topics will be returned)
     * @return void
     */
    public function consume(string $subscriptionId, callable $callback, string $topicRegex = '*'): void
    {
        $tenant = $this->getPulsarTenant();
        $namespace = $this->getPulsarNamespace();
        $topicRegex = 'event-action-' . $topicRegex;
        //$topicRegex = '.*';

        $config = new ConsumerConfiguration();
        $config->setConsumerType(Consumer::ConsumerShared);
        $config->setSchema(SchemaType::AVRO, "action", $this->getSchema(), []);

        $consumer = $this->client()->subscribeWithRegex("persistent://$tenant/$namespace/$topicRegex", $subscriptionId, $config);

        while (true) {
            $message = $consumer->receive();
            $data = json_decode($message->getDataAsString(), true);

            /** @var User */
            $user = $this->entitiesBuilder->single($data['user_guid']);
            
            /** @var Entity */
            $entity = $this->entitiesBuilder->single($data['entity_guid']);

            $event = new ActionEvent();
            $event->setUser($user)
                ->setEntity($entity)
                ->setAction($data['action'])
                ->setActionData($data['action_data']);

            if (call_user_func($callback, $event, $message) === true) {
                $consumer->acknowledge($message);
            }
        }
    }

    /**
     * Return the schema
     * @return string
     */
    protected function getSchema(): string
    {
        return json_encode([
            'type' => 'record', // ??
            'name' => 'action',
            'namespace' => 'engine',
            'fields' => [
                [
                    'name' => 'action',
                    'type' => 'string',
                ],
                [
                    'name' => 'action_data',
                    'type' => 'string',
                ],
                [
                    'name' => 'user_guid',
                    'type' => 'string',
                ],
                [
                    'name' => 'entity_urn',
                    'type' => 'string',
                ],
                [
                    'name' => 'entity_guid',
                    'type' => 'string',
                ],
                [
                    'name' => 'entity_owner_guid',
                    'type' => 'string',
                ],
                [
                    'name' => 'entity_type',
                    'type' => 'string'
                ],
                [
                    'name' => 'entity_subtype',
                    'type' => 'string'
                ],
            ]
        ]);
    }
}
