<?php declare(strict_types = 1);

namespace PHPStan\Rules\PhpDoc;

use PHPStan\Internal\AssertVariableResolver;
use PHPStan\Node\Expr\TypeExpr;
use PHPStan\Node\Printer\Printer;
use PHPStan\Reflection\InitializerExprContext;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ParametersAcceptorWithAsserts;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ErrorType;
use PHPStan\Type\VerbosityLevel;
use function array_key_exists;
use function sprintf;

class AssertRuleHelper
{

	public function __construct(private InitializerExprTypeResolver $initializerExprTypeResolver, private Printer $printer)
	{
	}

	/**
	 * @return RuleError[]
	 */
	public function check(ParametersAcceptor $acceptor): array
	{
		if (!$acceptor instanceof ParametersAcceptorWithAsserts) {
			return [];
		}

		$parametersByName = [];
		foreach ($acceptor->getParameters() as $parameter) {
			$parametersByName[$parameter->getName()] = $parameter->getType();
		}

		$context = InitializerExprContext::createEmpty();

		$errors = [];
		foreach ($acceptor->getAsserts()->getAll() as $assert) {
			$assertedExpr = AssertVariableResolver::map($assert->getParameter(), static function ($parameterName) use ($parametersByName, &$errors) {
				if (!array_key_exists($parameterName, $parametersByName)) {
					$errors[] = RuleErrorBuilder::message(sprintf('Assert references unknown parameter $%s.', $parameterName))->build();
					$type = new ErrorType();
				} else {
					$type = $parametersByName[$parameterName];
				}

				return new TypeExpr($type);
			});

			$assertedExprType = $this->initializerExprTypeResolver->getType($assertedExpr, $context);
			if ($assertedExprType instanceof ErrorType) {
				continue;
			}

			$assertedType = $assert->getType();

			$isSuperType = $assertedType->isSuperTypeOf($assertedExprType);
			if ($isSuperType->maybe()) {
				continue;
			}

			$assertedExprString = $this->printer->prettyPrintExpr($assert->getParameter());

			if ($assert->isNegated() ? $isSuperType->yes() : $isSuperType->no()) {
				$errors[] = RuleErrorBuilder::message(sprintf(
					'Asserted %stype %s for %s with type %s can never happen.',
					$assert->isNegated() ? 'negated ' : '',
					$assertedType->describe(VerbosityLevel::precise()),
					$assertedExprString,
					$assertedExprType->describe(VerbosityLevel::precise()),
				))->build();
			} elseif ($assert->isNegated() ? $isSuperType->no() : $isSuperType->yes()) {
				$errors[] = RuleErrorBuilder::message(sprintf(
					'Asserted %stype %s for %s with type %s does not narrow down the type.',
					$assert->isNegated() ? 'negated ' : '',
					$assertedType->describe(VerbosityLevel::precise()),
					$assertedExprString,
					$assertedExprType->describe(VerbosityLevel::precise()),
				))->build();
			}
		}

		return $errors;
	}

}