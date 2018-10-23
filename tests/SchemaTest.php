<?php

declare(strict_types=1);

namespace tests\Libero\Schemas;

use FluentDOM;
use FluentDOM\DOM\Document;
use FluentDOM\DOM\ProcessingInstruction;
use Libero\XmlValidator\Failure;
use Libero\XmlValidator\RelaxNgValidator;
use Libero\XmlValidator\ValidationFailed;
use Libero\XmlValidator\XmlValidator;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use function Functional\map;
use function preg_match;

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

            $xmlModel = $dom('substring-before(substring-after(/processing-instruction("xml-model"), \'"\'), \'"\')');
            $schema = "{$file->getPath()}/{$xmlModel}";

            $validator = new RelaxNgValidator($schema);

            $expectedFailures = map(
                $dom('/processing-instruction("expected-error")'),
                function (ProcessingInstruction $instruction) use ($file) : Failure {
                    $valid = preg_match('~line="([0-9]+)"\s+message="([^"]*?)"~', $instruction->nodeValue, $matches);

                    if (!$valid) {
                        throw new LogicException(
                            'Invalid expected-error processing instruction in '.
                            $file->getRelativePathname()
                        );
                    }

                    return new Failure($matches[2], (int) $matches[1]);
                }
            );

            yield $file->getRelativePathname() => [$dom, $validator, $expectedFailures];
        }
    }
}
