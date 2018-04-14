<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Fixer\Import;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;

/**
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 */
final class NoUnusedImportsFixer extends AbstractFixer
{
    /**
     * {@inheritdoc}
     */
    public function getDefinition()
    {
        return new FixerDefinition(
            'Unused `use` statements must be removed.',
            array(new CodeSample("<?php\nuse \\DateTime;\nuse \\Exception;\n\nnew DateTime();"))
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority()
    {
        // should be run after the SingleImportPerStatementFixer
        return -10;
    }

    /**
     * {@inheritdoc}
     */
    public function isCandidate(Tokens $tokens)
    {
        return $tokens->isTokenKindFound(T_USE);
    }

    /**
     * {@inheritdoc}
     */
    public function supports(\SplFileInfo $file)
    {
        $path = $file->getPathname();

        /*
         * @deprecated this exception will be removed on 3.0
         * some fixtures are auto-generated by Symfony and may contain unused use statements
         */
        if (false !== strpos($path, DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR)
            && false === strpos($path, DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR)
        ) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function applyFix(\SplFileInfo $file, Tokens $tokens)
    {
        $tokensAnalyzer = new TokensAnalyzer($tokens);
        $useDeclarationsIndexes = $tokensAnalyzer->getImportUseIndexes();

        if (0 === count($useDeclarationsIndexes)) {
            return;
        }

        $useDeclarations = $this->getNamespaceUseDeclarations($tokens, $useDeclarationsIndexes);
        $namespaceDeclarations = $this->getNamespaceDeclarations($tokens);
        $contentWithoutUseDeclarations = $this->generateCodeWithoutPartials($tokens, array_merge($namespaceDeclarations, $useDeclarations));
        $useUsages = $this->detectUseUsages($contentWithoutUseDeclarations, $useDeclarations);

        $this->removeUnusedUseDeclarations($tokens, $useDeclarations, $useUsages);
        $this->removeUsesInSameNamespace($tokens, $useDeclarations, $namespaceDeclarations);
    }

    /**
     * @param string $content
     * @param array  $useDeclarations
     *
     * @return array
     */
    private function detectUseUsages($content, array $useDeclarations)
    {
        $usages = array();

        foreach ($useDeclarations as $shortName => $useDeclaration) {
            $usages[$shortName] = (bool) Preg::match('/(?<![\$\\\\])(?<!->)\b'.preg_quote($shortName, '/').'\b/i', $content);
        }

        return $usages;
    }

    /**
     * @param Tokens $tokens
     * @param array  $partials
     *
     * @return string
     */
    private function generateCodeWithoutPartials(Tokens $tokens, array $partials)
    {
        $content = '';

        foreach ($tokens as $index => $token) {
            $allowToAppend = true;

            foreach ($partials as $partial) {
                if ($partial['start'] <= $index && $index <= $partial['end']) {
                    $allowToAppend = false;

                    break;
                }
            }

            if ($allowToAppend) {
                $content .= $token->getContent();
            }
        }

        return $content;
    }

    private function getNamespaceDeclarations(Tokens $tokens)
    {
        $namespaces = array();

        foreach ($tokens as $index => $token) {
            if (!$token->isGivenKind(T_NAMESPACE)) {
                continue;
            }

            $declarationEndIndex = $tokens->getNextTokenOfKind($index, array(';', '{'));

            $namespaces[] = array(
                'name' => trim($tokens->generatePartialCode($index + 1, $declarationEndIndex - 1)),
                'start' => $index,
                'end' => $declarationEndIndex,
            );
        }

        return $namespaces;
    }

    private function getNamespaceUseDeclarations(Tokens $tokens, array $useIndexes)
    {
        $uses = array();

        foreach ($useIndexes as $index) {
            $info = $this->getNamespaceUseDeclarationDetails($tokens, $index, $tokens->getNextTokenOfKind($index, array(';', array(T_CLOSE_TAG))));

            if (!$info['group']) {
                $uses[$info['shortName']] = $info;
            }
        }

        return $uses;
    }

    /**
     * @param Tokens $tokens
     * @param int    $index
     * @param int    $declarationEndIndex
     *
     * @return array
     */
    private function getNamespaceUseDeclarationDetails(Tokens $tokens, $index, $declarationEndIndex)
    {
        $fullName = $shortName = '';
        $aliased = false;

        for ($i = $index; $i <= $declarationEndIndex; ++$i) {
            $token = $tokens[$i];

            if ($token->equals(',') || $token->isGivenKind(CT::T_GROUP_IMPORT_BRACE_CLOSE)) {
                // do not touch group use declarations until the logic of this is added (for example: `use some\a\{ClassD};`)
                // ignore multiple use statements that should be split into few separate statements (for example: `use BarB, BarC as C;`)

                return array('group' => true);
            }

            if ($token->isWhitespace() || $token->isComment() || $token->isGivenKind(array(T_USE))) {
                continue;
            }

            if ($token->isGivenKind(T_STRING)) {
                $shortName = $token->getContent();
                if (!$aliased) {
                    $fullName .= $shortName;
                }
            } elseif ($token->isGivenKind(T_NS_SEPARATOR)) {
                $fullName .= $token->getContent();
            } elseif ($token->isGivenKind(T_AS)) {
                $aliased = true;
            }
        }

        return array(
            'fullName' => $fullName,
            'shortName' => $shortName,
            'aliased' => $aliased,
            'group' => false,
            'start' => $index,
            'end' => $declarationEndIndex,
        );
    }

    private function removeUnusedUseDeclarations(Tokens $tokens, array $useDeclarations, array $useUsages)
    {
        foreach ($useDeclarations as $shortName => $useDeclaration) {
            if (!$useUsages[$shortName]) {
                $this->removeUseDeclaration($tokens, $useDeclaration);
            }
        }
    }

    private function removeUseDeclaration(Tokens $tokens, array $useDeclaration)
    {
        for ($index = $useDeclaration['end'] - 1; $index >= $useDeclaration['start']; --$index) {
            if ($tokens[$index]->isComment()) {
                continue;
            }

            if (!$tokens[$index]->isWhitespace() || false === strpos($tokens[$index]->getContent(), "\n")) {
                $tokens->clearTokenAndMergeSurroundingWhitespace($index);

                continue;
            }

            // when multi line white space keep the line feed if the previous token is a comment
            $prevIndex = $tokens->getPrevNonWhitespace($index);
            if ($tokens[$prevIndex]->isComment()) {
                $content = $tokens[$index]->getContent();
                $tokens[$index] = new Token(array(T_WHITESPACE, substr($content, strrpos($content, "\n")))); // preserve indent only
            } else {
                $tokens->clearTokenAndMergeSurroundingWhitespace($index);
            }
        }

        if ($tokens[$useDeclaration['end']]->equals(';')) { // do not remove `? >`
            $tokens->clearAt($useDeclaration['end']);
        }

        // remove white space above and below where the `use` statement was

        $prevIndex = $useDeclaration['start'] - 1;
        $prevToken = $tokens[$prevIndex];

        if ($prevToken->isWhitespace()) {
            $content = rtrim($prevToken->getContent(), " \t");

            if ('' === $content) {
                $tokens->clearAt($prevIndex);
            } else {
                $tokens[$prevIndex] = new Token(array(T_WHITESPACE, $content));
            }

            $prevToken = $tokens[$prevIndex];
        }

        if (!isset($tokens[$useDeclaration['end'] + 1])) {
            return;
        }

        $nextIndex = $tokens->getNonEmptySibling($useDeclaration['end'], 1);
        if (null === $nextIndex) {
            return;
        }

        $nextToken = $tokens[$nextIndex];

        if ($nextToken->isWhitespace()) {
            $content = Preg::replace(
                "#^\r\n|^\n#",
                '',
                ltrim($nextToken->getContent(), " \t"),
                1
            );

            if ('' !== $content) {
                $tokens[$nextIndex] = new Token(array(T_WHITESPACE, $content));
            } else {
                $tokens->clearAt($nextIndex);
            }

            $nextToken = $tokens[$nextIndex];
        }

        if ($prevToken->isWhitespace() && $nextToken->isWhitespace()) {
            $content = $prevToken->getContent().$nextToken->getContent();

            if ('' !== $content) {
                $tokens[$nextIndex] = new Token(array(T_WHITESPACE, $content));
            } else {
                $tokens->clearAt($nextIndex);
            }

            $tokens->clearAt($prevIndex);
        }
    }

    private function removeUsesInSameNamespace(Tokens $tokens, array $useDeclarations, array $namespaceDeclarations)
    {
        // safeguard for files with multiple namespaces to avoid breaking them until we support this case
        if (1 !== count($namespaceDeclarations)) {
            return;
        }

        $namespace = $namespaceDeclarations[0]['name'];
        $nsLength = strlen($namespace.'\\');

        foreach ($useDeclarations as $useDeclaration) {
            if ($useDeclaration['aliased']) {
                continue;
            }

            $useDeclarationFullName = ltrim($useDeclaration['fullName'], '\\');

            if (0 !== strpos($useDeclarationFullName, $namespace.'\\')) {
                continue;
            }

            $partName = substr($useDeclarationFullName, $nsLength);

            if (false === strpos($partName, '\\')) {
                $this->removeUseDeclaration($tokens, $useDeclaration);
            }
        }
    }
}
