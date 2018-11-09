<?php

declare(strict_types=1);

namespace tests\Libero\Schemas;

use FluentDOM;
use FluentDOM\DOM\Document;
use FluentDOM\DOM\ProcessingInstruction;
use Libero\XmlValidator\CompositeValidator;
use Libero\XmlValidator\Failure;
use Libero\XmlValidator\RelaxNgValidator;
use Libero\XmlValidator\SchematronValidator;
use Libero\XmlValidator\ValidationFailed;
use Libero\XmlValidator\XmlValidator;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use function array_reduce;
use function count;
use function Functional\map;
use function is_readable;
use function preg_match_all;
use const PREG_SET_ORDER;

final class SchemaTest extends TestCase
{
    /**
     * @test
     * @dataProvider validFileProvider
     */
    public function valid_documents_pass(Document $document, XmlValidator $validator) : void
    {
        $this->expectNotToPerformAssertions();

        $validator->validate($document);
    }

    /**
     * @test
     * @dataProvider invalidFileProvider
     */
    public function invalid_documents_fail(Document $document, XmlValidator $validator, array $expected) : void
    {
        try {
            $validator->validate($document);
            $this->fail('Document is considered valid when it is not');
        } catch (ValidationFailed $e) {
            $this->assertEquals($expected, $e->getFailures());
        }
    }

    public function validFileProvider() : iterable
    {
        $files = Finder::create()->files()
            ->name('*.xml')
            ->in(__DIR__)
            ->path('~/valid/~');

        return $this->extractSchemas($files);
    }

    public function invalidFileProvider() : iterable
    {
        $files = Finder::create()->files()
            ->name('*.xml')
            ->in(__DIR__)
            ->path('~/invalid/~');

        return $this->extractSchemas($files);
    }

    private function extractSchemas(Finder $files) : iterable
    {
        foreach ($files as $file) {
            $dom = FluentDOM::load($file->getContents());

            yield $file->getRelativePathname() => [
                $dom,
                $this->findValidator($dom, $file),
                $this->findExpectedFailures($dom, $file),
            ];
        }
    }

    private function findValidator(Document $dom, SplFileInfo $file) : XmlValidator
    {
        $validators = map(
            $dom('/processing-instruction("xml-model")'),
            function (ProcessingInstruction $instruction) use ($file) {
                $parsed = $this->parseProcessingInstruction($instruction, $file);
                $schema = "{$file->getPath()}/{$parsed['href']}";
                if (!is_readable($schema)) {
                    throw new LogicException("Failed to read schema {$schema} in {$file->getRelativePathname()}");
                }
                switch ($parsed['schematypens']) {
                    case 'http://relaxng.org/ns/structure/1.0':
                        return new RelaxNgValidator($schema);
                    case 'http://purl.oclc.org/dsdl/schematron':
                        return new SchematronValidator($schema);
                    default:
                        throw new LogicException(
                            "Unknown schematypens {$parsed['schematypens']} in {$file->getRelativePathname()}"
                        );
                }
            }
        );

        return 1 === count($validators) ? $validators[0] : new CompositeValidator(...$validators);
    }

    private function findExpectedFailures(Document $dom, SplFileInfo $file) : array
    {
        return map(
            $dom('/processing-instruction("expected-error")'),
            function (ProcessingInstruction $instruction) use ($dom, $file) : Failure {
                $parsed = $this->parseProcessingInstruction($instruction, $file);

                if (isset($parsed['node'])) {
                    $node = $dom->xpath()->evaluate($parsed['node'], null, true)->item(0);
                    if (null === $node) {
                        throw new LogicException(
                            "Failed to match {$parsed['node']} in {$file->getRelativePathname()}"
                        );
                    }
                }

                return new Failure($parsed['message'], (int) $parsed['line'], $node ?? null);
            }
        );
    }

    private function parseProcessingInstruction(ProcessingInstruction $instruction, SplFileInfo $file) : array
    {
        $valid = preg_match_all(
            '~([a-z]+)="([^"]*?)"~',
            $instruction->nodeValue,
            $matches,
            PREG_SET_ORDER
        );

        if (!$valid) {
            throw new LogicException("Failed to parse processing instruction in {$file->getRelativePathname()}");
        }

        return array_reduce(
            $matches,
            function (array $carry, array $parts) {
                $carry[$parts[1]] = $parts[2];

                return $carry;
            },
            []
        );
    }
}
