<?php declare(strict_types=1);

namespace SearchSolr\Test;

use Omeka\Test\AbstractHttpControllerTestCase;

abstract class TestCase extends AbstractHttpControllerTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->getApplication();
    }

    protected function login($email, $password)
    {
        $serviceLocator = $this->getServiceLocator();
        $auth = $serviceLocator->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity($email);
        $adapter->setCredential($password);
        return $auth->authenticate();
    }

    protected function loginAsAdmin(): void
    {
        $this->login('admin@example.com', 'root');
    }

    protected function getServiceLocator()
    {
        return $this->getApplication()->getServiceManager();
    }

    protected function api()
    {
        return $this->getServiceLocator()->get('Omeka\ApiManager');
    }
}
