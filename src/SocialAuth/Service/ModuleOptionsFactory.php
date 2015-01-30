<?php
/**
 * SocialAuth Module
 *
 * @category   SocialAuth
 * @package    SocialAuth_Service
 */

namespace SocialAuth\Service;

use SocialAuth\Options;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * @category   SocialAuth
 * @package    SocialAuth_Service
 */
class ModuleOptionsFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $services)
    {
        $config = $services->get('Configuration');

        return new Options\ModuleOptions(isset($config['social-auth']) ? $config['social-auth'] : array());
    }
}
