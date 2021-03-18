<?php
/**
 * Minds OAuth UserRepository
 */
namespace Minds\Core\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Minds\Core\OAuth\Entities\UserEntity;
use Minds\Entities\User;
use Minds\Core\Di\Di;
use Minds\Core\Security\Password;

class UserRepository implements UserRepositoryInterface
{
    /** @var Password */
    private $password;

    /** @var Delegates\SentryScopeDelegate */
    private $sentryScopeDelegate;

    /** @var User */
    public $mockUser = false;

    public function __construct(Password $password = null, Delegates\SentryScopeDelegate $sentryScopeDelegate = null)
    {
        $this->password = $password ?: Di::_()->get('Security\Password');
        $this->sentryScopeDelegate = $sentryScopeDelegate ?? new Delegates\SentryScopeDelegate;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserEntityByUserCredentials(
        $username,
        $password,
        $grantType,
        ClientEntityInterface $clientEntity
    ) {
        if (!$username || !$password) {
            return false;
        }

        if ($this->mockUser) {
            $user = $this->mockUser;
        } else {
            $user = new User(strtolower($username));
        }

        if (!$user->getGuid()) {
            return false;
        }

        if (!$this->password->check($user, $password)) {
            return false;
        }

        $entity = new UserEntity();
        $entity->setIdentifier($user->getGuid());

        // Update Sentry scope with our user
        $this->sentryScopeDelegate->onGetUserEntity($entity);

        return $entity;
    }
}
