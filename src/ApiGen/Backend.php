<?php

/**
 * This file is part of the ApiGen (http://apigen.org)
 *
 * For the full copyright and license information, please view
 * the file license.md that was distributed with this source code.
 */

namespace ApiGen;

use ApiGen\Generator\Generator;
use ApiGen\Reflection\ReflectionClass;
use ApiGen\Reflection\ReflectionFunction;
use ApiGen\Reflection\ReflectionParameter;
use TokenReflection;
use TokenReflection\IReflectionConstant;
use TokenReflection\IReflectionFunction;
use TokenReflection\Broker;
use TokenReflection\Resolver;


/**
 * Customized TokenReflection broker backend.
 * Adds internal classes from @param, @var, @return, @throws annotations as well
 * as parent classes to the overall class list.
 */
class Backend extends Broker\Backend\Memory
{

	/**
	 * Cache of processed token streams.
	 *
	 * @var array
	 */
	private $fileCache = array();

	/**
	 * @var Generator
	 */
	private $generator;


	/**
	 * @param Generator $generator
	 */
	public function __construct(Generator $generator)
	{
		$this->generator = $generator;
	}


	/**
	 * Deletes all cached token streams.
	 */
	public function __destruct()
	{
		foreach ($this->fileCache as $file) {
			unlink($file);
		}
	}


	/**
	 * Prepares and returns used class lists.
	 *
	 * @return array
	 */
	protected function parseClassLists()
	{
		$allClasses = array(
			self::TOKENIZED_CLASSES => array(),
			self::INTERNAL_CLASSES => array(),
			self::NONEXISTENT_CLASSES => array()
		);

		$declared = array_flip(array_merge(get_declared_classes(), get_declared_interfaces()));

		foreach ($this->getNamespaces() as $namespace) {
			/** @var TokenReflection\ReflectionNamespace $namespace */
			foreach ($namespace->getClasses() as $name => $trClass) {
				$class = new Reflection\ReflectionClass($trClass, $this->generator);
				$allClasses[self::TOKENIZED_CLASSES][$name] = $class;
				if ( ! $class->isDocumented()) {
					continue;
				}

				/** @var TokenReflection\ReflectionClass $trClass */
				foreach (array_merge($trClass->getParentClasses(), $trClass->getInterfaces()) as $parentName => $parent) {
					/** @var TokenReflection\ReflectionClass $parent */
					if ($parent->isInternal()) {
						if ( ! isset($allClasses[self::INTERNAL_CLASSES][$parentName])) {
							$allClasses[self::INTERNAL_CLASSES][$parentName] = $parent;
						}

					} elseif ( ! $parent->isTokenized()) {
						if ( ! isset($allClasses[self::NONEXISTENT_CLASSES][$parentName])) {
							$allClasses[self::NONEXISTENT_CLASSES][$parentName] = $parent;
						}
					}
				}
			}
		}

		/** @var ReflectionClass $class */
		foreach ($allClasses[self::TOKENIZED_CLASSES] as $class) {
			if ( ! $class->isDocumented()) {
				continue;
			}

			foreach ($class->getOwnMethods() as $method) {
				$allClasses = $this->processFunction($declared, $allClasses, $method);
			}

			foreach ($class->getOwnProperties() as $property) {
				$annotations = $property->getAnnotations();

				if ( ! isset($annotations['var'])) {
					continue;
				}

				foreach ($annotations['var'] as $doc) {
					foreach (explode('|', preg_replace('~\\s.*~', '', $doc)) as $name) {
						if ($name = rtrim($name, '[]')) {
							$name = Resolver::resolveClassFQN($name, $class->getNamespaceAliases(), $class->getNamespaceName());
							$allClasses = $this->addClass($declared, $allClasses, $name);
						}
					}
				}
			}
		}

		foreach ($this->getFunctions() as $function) {
			$allClasses = $this->processFunction($declared, $allClasses, $function);
		}

		array_walk_recursive($allClasses, function (&$reflection, $name, Generator $generator) {
			if ( ! $reflection instanceof Reflection\ReflectionClass) {
				$reflection = new Reflection\ReflectionClass($reflection, $generator);
			}
		}, $this->generator);

		return $allClasses;
	}


	/**
	 * Processes a function/method and adds classes from annotations to the overall class array.
	 *
	 * @param array $declared
	 * @param array $allClasses
	 * @param ReflectionFunction|\TokenReflection\IReflectionFunctionBase $function
	 * @return array
	 */
	private function processFunction(array $declared, array $allClasses, $function)
	{
		$parsedAnnotations = array('param', 'return', 'throws');

		$annotations = $function->getAnnotations();
		foreach ($parsedAnnotations as $annotation) {
			if ( ! isset($annotations[$annotation])) {
				continue;
			}

			foreach ($annotations[$annotation] as $doc) {
				foreach (explode('|', preg_replace('~\\s.*~', '', $doc)) as $name) {
					if ($name) {
						$name = Resolver::resolveClassFQN(rtrim($name, '[]'), $function->getNamespaceAliases(), $function->getNamespaceName());
						$allClasses = $this->addClass($declared, $allClasses, $name);
					}
				}
			}
		}

		/** @var ReflectionParameter $param */
		foreach ($function->getParameters() as $param) {
			if ($hint = $param->getClassName()) {
				$allClasses = $this->addClass($declared, $allClasses, $hint);
			}
		}

		return $allClasses;
	}


	/**
	 * Adds a class to list of classes.
	 *
	 * @param array $declared
	 * @param array $allClasses
	 * @param string $name
	 * @return array
	 */
	private function addClass(array $declared, array $allClasses, $name)
	{
		$name = ltrim($name, '\\');

		if ( ! isset($declared[$name]) || isset($allClasses[self::TOKENIZED_CLASSES][$name])
			|| isset($allClasses[self::INTERNAL_CLASSES][$name]) || isset($allClasses[self::NONEXISTENT_CLASSES][$name])
		) {
			return $allClasses;
		}

		$parameterClass = $this->getBroker()->getClass($name);
		if ($parameterClass->isInternal()) {
			$allClasses[self::INTERNAL_CLASSES][$name] = $parameterClass;
			foreach (array_merge($parameterClass->getInterfaces(), $parameterClass->getParentClasses()) as $parentClass) {
				if ( ! isset($allClasses[self::INTERNAL_CLASSES][$parentName = $parentClass->getName()])) {
					$allClasses[self::INTERNAL_CLASSES][$parentName] = $parentClass;
				}
			}

		} elseif ( ! $parameterClass->isTokenized() && ! isset($allClasses[self::NONEXISTENT_CLASSES][$name])) {
			$allClasses[self::NONEXISTENT_CLASSES][$name] = $parameterClass;
		}

		return $allClasses;
	}


	/**
	 * Returns all constants from all namespaces.
	 *
	 * @return array
	 */
	public function getConstants()
	{
		$generator = $this->generator;
		return array_map(function (IReflectionConstant $constant) use ($generator) {
			return new Reflection\ReflectionConstant($constant, $generator);
		}, parent::getConstants());
	}


	/**
	 * Returns all functions from all namespaces.
	 *
	 * @return array
	 */
	public function getFunctions()
	{
		$generator = $this->generator;
		return array_map(function (IReflectionFunction $function) use ($generator) {
			return new Reflection\ReflectionFunction($function, $generator);
		}, parent::getFunctions());
	}

}
