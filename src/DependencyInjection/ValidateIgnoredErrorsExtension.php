<?php declare(strict_types = 1);

namespace PHPStan\DependencyInjection;

use Hoa\Compiler\Llk\Llk;
use Hoa\File\Read;
use Nette\DI\CompilerExtension;
use Nette\Utils\RegexpException;
use Nette\Utils\Strings;
use PHPStan\Analyser\NameScope;
use PHPStan\Command\IgnoredRegexValidator;
use PHPStan\PhpDoc\DirectTypeNodeResolverExtensionRegistryProvider;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDoc\TypeNodeResolverExtensionRegistry;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\Reflection\ReflectionProvider\DirectReflectionProviderProvider;
use PHPStan\Reflection\ReflectionProvider\DummyReflectionProvider;
use PHPStan\Reflection\ReflectionProviderStaticAccessor;
use PHPStan\Type\DirectTypeAliasResolverProvider;
use PHPStan\Type\Type;
use PHPStan\Type\TypeAliasResolver;
use function array_keys;
use function array_map;
use function count;
use function implode;
use function is_array;
use function sprintf;

class ValidateIgnoredErrorsExtension extends CompilerExtension
{

	/**
	 * @throws InvalidIgnoredErrorPatternsException
	 */
	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		if (!$builder->parameters['__validate']) {
			return;
		}

		$ignoreErrors = $builder->parameters['ignoreErrors'];
		if (count($ignoreErrors) === 0) {
			return;
		}

		/** @throws void */
		$parser = Llk::load(new Read('hoa://Library/Regex/Grammar.pp'));
		$reflectionProvider = new DummyReflectionProvider();
		ReflectionProviderStaticAccessor::registerInstance($reflectionProvider);
		$ignoredRegexValidator = new IgnoredRegexValidator(
			$parser,
			new TypeStringResolver(
				new Lexer(),
				new TypeParser(new ConstExprParser()),
				new TypeNodeResolver(
					new DirectTypeNodeResolverExtensionRegistryProvider(
						new class implements TypeNodeResolverExtensionRegistry {

							public function getExtensions(): array
							{
								return [];
							}

						},
					),
					new DirectReflectionProviderProvider($reflectionProvider),
					new DirectTypeAliasResolverProvider(new class implements TypeAliasResolver {

						public function hasTypeAlias(string $aliasName, ?string $classNameScope): bool
						{
							return false;
						}

						public function resolveTypeAlias(string $aliasName, NameScope $nameScope): ?Type
						{
							return null;
						}

					}),
				),
			),
		);
		$errors = [];

		foreach ($ignoreErrors as $ignoreError) {
			try {
				if (is_array($ignoreError)) {
					if (isset($ignoreError['count'])) {
						continue; // ignoreError coming from baseline will be correct
					}
					$ignoreMessage = $ignoreError['message'];
				} else {
					$ignoreMessage = $ignoreError;
				}

				Strings::match('', $ignoreMessage);
				$validationResult = $ignoredRegexValidator->validate($ignoreMessage);
				$ignoredTypes = $validationResult->getIgnoredTypes();
				if (count($ignoredTypes) > 0) {
					$errors[] = $this->createIgnoredTypesError($ignoreMessage, $ignoredTypes);
				}

				if ($validationResult->hasAnchorsInTheMiddle()) {
					$errors[] = $this->createAnchorInTheMiddleError($ignoreMessage);
				}

				if ($validationResult->areAllErrorsIgnored()) {
					$errors[] = sprintf("Ignored error %s has an unescaped '%s' which leads to ignoring all errors. Use '%s' instead.", $ignoreMessage, $validationResult->getWrongSequence(), $validationResult->getEscapedWrongSequence());
				}
			} catch (RegexpException $e) {
				$errors[] = $e->getMessage();
			}
		}

		if (count($errors) === 0) {
			return;
		}

		throw new InvalidIgnoredErrorPatternsException($errors);
	}

	/**
	 * @param array<string, string> $ignoredTypes
	 */
	private function createIgnoredTypesError(string $regex, array $ignoredTypes): string
	{
		return sprintf(
			"Ignored error %s has an unescaped '|' which leads to ignoring more errors than intended. Use '\\|' instead.\n%s",
			$regex,
			sprintf(
				"It ignores all errors containing the following types:\n%s",
				implode("\n", array_map(static fn (string $typeDescription): string => sprintf('* %s', $typeDescription), array_keys($ignoredTypes))),
			),
		);
	}

	private function createAnchorInTheMiddleError(string $regex): string
	{
		return sprintf("Ignored error %s has an unescaped anchor '$' in the middle. This leads to unintended behavior. Use '\\$' instead.", $regex);
	}

}
