<?php

declare(strict_types=1);

namespace Rector\Symfony\CodeQuality\Rector\MethodCall;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;
use Rector\Symfony\Enum\SymfonyClass;
use Rector\Symfony\NodeAnalyzer\LiteralCallLikeConstFetchReplacer;
use Rector\Symfony\ValueObject\ConstantMap\SymfonyRequestConstantMap;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Rector\Symfony\Tests\CodeQuality\Rector\MethodCall\LiteralGetToRequestClassConstantRector\LiteralGetToRequestClassConstantRectorTest
 */
final class LiteralGetToRequestClassConstantRector extends AbstractRector
{
    public function __construct(
        private readonly LiteralCallLikeConstFetchReplacer $literalCallLikeConstFetchReplacer,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Replace "GET" string by Symfony Request object class constants', [
            new CodeSample(
                <<<'CODE_SAMPLE'
use Symfony\Component\Form\FormBuilderInterface;

final class SomeClass
{
    public function detail(FormBuilderInterface $formBuilder)
    {
        $formBuilder->setMethod('GET');
    }
}
CODE_SAMPLE

                ,
                <<<'CODE_SAMPLE'
use Symfony\Component\Form\FormBuilderInterface;

final class SomeClass
{
    public function detail(FormBuilderInterface $formBuilder)
    {
        $formBuilder->setMethod(\Symfony\Component\HttpFoundation\Request::GET);
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [MethodCall::class, StaticCall::class];
    }

    /**
     * @param MethodCall|StaticCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node instanceof StaticCall) {
            return $this->refactorStaticCall($node);
        }

        // for client, the transitional dependency to browser-kit might be missing and cause fatal error on PHPStan reflection
        // in most cases that should be skipped, @changelog https://github.com/rectorphp/rector/issues/7135
        if (
            $this->reflectionProvider->hasClass(SymfonyClass::ABSTRACT_BROWSER) && (
                $this->isObjectType($node->var, new ObjectType(SymfonyClass::HTTP_CLIENT)) |
                $this->isObjectType($node->var, new ObjectType(SymfonyClass::KERNEL_BROWSER))
            )
        ) {
            return $this->refactorClientMethodCall($node);
        }

        if (! $this->isName($node->name, 'setMethod')) {
            return null;
        }

        if (! $this->isObjectType($node->var, new ObjectType(SymfonyClass::FORM_BUILDER))) {
            return null;
        }

        return $this->literalCallLikeConstFetchReplacer->replaceArgOnPosition(
            $node,
            0,
            SymfonyClass::REQUEST,
            SymfonyRequestConstantMap::METHOD_TO_CONST
        );
    }

    private function refactorStaticCall(StaticCall $staticCall): StaticCall|null
    {
        if (! $this->isName($staticCall->name, 'create')) {
            return null;
        }

        if (! $this->isObjectType($staticCall->class, new ObjectType(SymfonyClass::REQUEST))) {
            return null;
        }

        return $this->literalCallLikeConstFetchReplacer->replaceArgOnPosition(
            $staticCall,
            1,
            SymfonyClass::REQUEST,
            SymfonyRequestConstantMap::METHOD_TO_CONST
        );
    }

    private function refactorClientMethodCall(MethodCall $methodCall): MethodCall|null
    {
        if (! $this->isName($methodCall->name, 'request')) {
            return null;
        }

        return $this->literalCallLikeConstFetchReplacer->replaceArgOnPosition(
            $methodCall,
            0,
            SymfonyClass::REQUEST,
            SymfonyRequestConstantMap::METHOD_TO_CONST
        );
    }
}
