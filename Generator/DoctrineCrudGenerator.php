<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sensio\Bundle\GeneratorBundle\Generator;

use Symfony\Component\HttpKernel\Util\Filesystem;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

/**
 * Generates a CRUD controller.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class DoctrineCrudGenerator extends Generator
{
    private $filesystem;
    private $skeletonDir;
    private $routePrefix;
    private $routeNamePrefix;
    private $bundle;
    private $entity;
    private $metadata;
    private $format;
    private $actions;
    private $controllerDir;

    /**
     * Constructor.
     *
     * @param Filesystem $filesystem A Filesystem instance
     * @param string $skeletonDir Path to the skeleton directory
     */
    public function __construct(Filesystem $filesystem, $skeletonDir)
    {
        $this->filesystem  = $filesystem;
        $this->skeletonDir = $skeletonDir;
    }

    /**
     * Generate the CRUD controller.
     *
     * @param BundleInterface $bundle A bundle object
     * @param string $entity The entity relative class name
     * @param ClassMetadataInfo $metadata The entity class metadata
     * @param string $format The configuration format (xml, yaml, annotation)
     * @param string $routePrefix The route name prefix
     * @param array $needWriteActions Wether or not to generate write actions
     * @param array $dir The directory where to generate the controller
     *
     * @throws \RuntimeException
     */
    public function generate(BundleInterface $bundle, $entity, ClassMetadataInfo $metadata, $format, $routePrefix, $needWriteActions, $dir = 'Controller')
    {
        $this->routePrefix = $routePrefix;
        $this->routeNamePrefix = str_replace('/', '_', $routePrefix);
        $this->actions = $needWriteActions ? array('index', 'show', 'new', 'edit', 'delete') : array('index', 'show');

        if (count($metadata->identifier) > 1) {
            throw new \RuntimeException('The CRUD generator does not support entity classes with multiple primary keys.');
        }

        if (!in_array('id', $metadata->identifier)) {
            throw new \RuntimeException('The CRUD generator expects the entity object has a primary key field named "id" with a getId() method.');
        }

        $this->entity   = $entity;
        $this->bundle   = $bundle;
        $this->metadata = $metadata;
        $this->setFormat($format);
        $this->controllerDir = $dir;

        $this->generateControllerClass();

        $subDir = $this->controllerDir == 'Controller' ? '' : str_replace('Controller/', '', $this->controllerDir).'/';
        $dir = sprintf('%s/Resources/views/%s', $this->bundle->getPath(), $subDir.str_replace('\\', '/', $this->entity));

        if (!file_exists($dir)) {
            $this->filesystem->mkdir($dir, 0777);
        }

        $this->generateIndexView($dir);

        if (in_array('show', $this->actions)) {
            $this->generateShowView($dir);
        }

        if (in_array('new', $this->actions)) {
            $this->generateFormView($dir);
            $this->generateNewView($dir);
        }

        if (in_array('edit', $this->actions)) {
            $this->generateEditView($dir);
        }

        $this->generateTestClass();
        $this->generateConfiguration();
    }

    /**
     * Sets the configuration format.
     *
     * @param string $format The configuration format
     */
    private function setFormat($format)
    {
        switch ($format) {
            case 'yml':
            case 'xml':
            case 'php':
            case 'annotation':
                $this->format = $format;
                break;
            default:
                $this->format = 'yml';
                break;
        }
    }

    /**
     * Generates the routing configuration.
     *
     */
    private function generateConfiguration()
    {
        if (!in_array($this->format, array('yml', 'xml', 'php'))) {
            return;
        }

        $target = sprintf(
            '%s/Resources/config/routing/%s.%s', 
            $this->bundle->getPath(),
            strtolower(str_replace('\\', '_', $this->entity)),
            $this->format
        );

        $this->renderFile($this->skeletonDir, 'config/routing.'.$this->format, $target, array(
            'actions'           => $this->actions,
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'bundle'            => $this->bundle->getName(),
            'entity'            => $this->entity,
        ));
    }

    /**
     * Generates the controller class only.
     *
     */
    private function generateControllerClass()
    {
        $dir = $this->bundle->getPath();

        $parts = explode('\\', $this->entity);
        $entityClass = array_pop($parts);
        $entityNamespace = implode('\\', $parts);
        $controllerNamespace = str_replace('/', '\\', $this->controllerDir);

        $target = sprintf(
            '%s/%s/%s/%sController.php',
            $dir,
            $this->controllerDir,
            str_replace('\\', '/', $entityNamespace),
            $entityClass
        );

        if (file_exists($target)) {
            throw new \RuntimeException('Unable to generate the controller as it already exists.');
        }

        $this->renderFile($this->skeletonDir, 'controller.php', $target, array(
            'actions'              => $this->actions,
            'route_prefix'         => $this->routePrefix,
            'route_name_prefix'    => $this->routeNamePrefix,
            'dir'                  => $this->skeletonDir,
            'bundle'               => $this->bundle->getName(),
            'entity'               => $this->entity,
            'entity_class'         => $entityClass,
            'namespace'            => $this->bundle->getNamespace(),
            'controller_namespace' => $controllerNamespace,
            'entity_namespace'     => $entityNamespace,
            'format'               => $this->format,
        ));
    }

    /**
     * Generates the functional test class only.
     *
     */
    private function generateTestClass()
    {
        $parts = explode('\\', $this->entity);
        $entityClass = array_pop($parts);
        $entityNamespace = implode('\\', $parts);
        $controllerNamespace = str_replace('/', '\\', $this->controllerDir);

        $dir    = $this->bundle->getPath() .'/Tests/'.$this->controllerDir;
        $target = $dir .'/'. str_replace('\\', '/', $entityNamespace).'/'. $entityClass .'ControllerTest.php';

        $this->renderFile($this->skeletonDir, 'tests/test.php', $target, array(
            'route_prefix'         => $this->routePrefix,
            'route_name_prefix'    => $this->routeNamePrefix,
            'entity'               => $this->entity,
            'entity_class'         => $entityClass,
            'namespace'            => $this->bundle->getNamespace(),
            'entity_namespace'     => $entityNamespace,
            'controller_namespace' => $controllerNamespace,
            'actions'              => $this->actions,
            'dir'                  => $this->skeletonDir,
        ));
    }

    /**
     * Generates the index.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    private function generateIndexView($dir)
    {
        $this->renderFile($this->skeletonDir, 'views/index.html.twig', $dir.'/index.html.twig', array(
            'dir'               => $this->skeletonDir,
            'entity'            => $this->entity,
            'fields'            => $this->metadata->fieldMappings,
            'actions'           => $this->actions,
            'record_actions'    => $this->getRecordActions(),
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
        ));
    }

    /**
     * Generates the show.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    private function generateShowView($dir)
    {
        $this->renderFile($this->skeletonDir, 'views/show.html.twig', $dir.'/show.html.twig', array(
            'dir'               => $this->skeletonDir,
            'entity'            => $this->entity,
            'fields'            => $this->metadata->fieldMappings,
            'actions'           => $this->actions,
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
        ));
    }

    /**
     * Generates the new.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    private function generateNewView($dir)
    {
        $this->renderFile($this->skeletonDir, 'views/new.html.twig', $dir.'/new.html.twig', array(
            'dir'               => $this->skeletonDir,
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'entity'            => $this->entity,
            'actions'           => $this->actions,
            'bundle'            => $this->bundle->getName(),
            'form_dir'          => $this->getFormTemplateDir($dir),
        ));
    }

    /**
     * Generates the edit.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    private function generateEditView($dir)
    {
        $this->renderFile($this->skeletonDir, 'views/edit.html.twig', $dir.'/edit.html.twig', array(
            'dir'               => $this->skeletonDir,
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'entity'            => $this->entity,
            'actions'           => $this->actions,
            'bundle'            => $this->bundle->getName(),
            'form_dir'          => $this->getFormTemplateDir($dir),
        ));
    }

    private function generateFormView($dir)
    {
        $this->renderFile($this->skeletonDir, 'views/form.html.twig', $dir.'/form.html.twig', array(
            'dir'               => $this->skeletonDir,
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'entity'            => $this->entity,
            'actions'           => $this->actions,
            'bundle'            => $this->bundle->getName(),
            'form_dir'          => $this->getFormTemplateDir($dir),
        ));
    }

    /**
     * Returns an array of record actions to generate (edit, show).
     *
     * @return array
     */
    private function getRecordActions()
    {
        return array_filter($this->actions, function($item) {
            return in_array($item, array('show', 'edit'));
        });
    }

    /**
     * Returns the directory of the form template used in the edit and new
     * twig template to includ it.
     *
     * @param $dir
     * @return string
     */
    private function getFormTemplateDir($dir)
    {
        $subDir  = '';
        $baseDir = substr($dir, strrpos($dir, '/') + 1);

        if ($this->controllerDir != 'Controller') {
            $subDir = str_replace('Controller/', '', $this->controllerDir).'/';
        }

        return $subDir.$baseDir;
    }
}
