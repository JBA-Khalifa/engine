<?php
namespace Minds\Core\Subscriptions\Graph;

use Exception;
use Minds\Api\Exportable;
use Minds\Core;
use Minds\Api\Factory;
use Minds\Core\Di\Di;
use Zend\Diactoros\Response\JsonResponse;
use Zend\Diactoros\ServerRequest;

/**
 * Subscriptions Controller
 * @package Minds\Core\Subscriptions
 */
class Controller
{
    /** @var Manager */
    protected $manager;

    /** @var ACL */
    protected $acl;

    /**
     * Controller constructor.
     * @param null $manager
     */
    public function __construct(
        $manager = null,
        $acl = null
    ) {
        $this->manager = $manager ?: new Manager();
        $this->acl = $acl ?? Di::_()->get('Security\ACL');
    }

    /**
     * Gets the list of subscriptions
     * @param ServerRequest $request
     * @return JsonResponse
     * @throws Exception
     */
    public function getSubscriptions(ServerRequest $request): JsonResponse
    {
        return $this->getByType('subscriptions', $request);
    }

    /**
     * Gets the list of subscribers
     * @param ServerRequest $request
     * @return JsonResponse
     * @throws Exception
     */
    public function getSubscribers(ServerRequest $request): JsonResponse
    {
        return $this->getByType('subscribers', $request);
    }

    /**
     * Gets a list by type
     * @internal
     * @param string $type
     * @param ServerRequest $request
     * @return JsonResponse
     * @throws Exception
     */
    protected function getByType(string $type, ServerRequest $request): JsonResponse
    {
        $guid = $request->getAttribute('parameters')['guid'] ?? null;

        if (!$guid) {
            throw new Exception('Invalid GUID');
        }

        $this->manager
            ->setUserGuid($guid);

        $response = $this->manager->getList(
            (new RepositoryGetOptions())
                ->setType($type)
                ->setSearchQuery($request->getQueryParams()['q'] ?? '')
                ->setLimit((int) ($request->getQueryParams()['limit'] ?? 12))
                ->setOffset((int) ($request->getQueryParams()['from_timestamp'] ?? 0))
        );

        $entities = array_filter($response->toArray(), function ($user) {
            return ($user->enabled != 'no' && $user->banned != 'yes' && $this->acl->read($user, Core\Session::getLoggedinUser()));
        });

        return new JsonResponse([
            'status' => 'success',
            'entities' => Exportable::_($entities),
            'load-next' => $response->getPagingToken(),
        ]);
    }
}
