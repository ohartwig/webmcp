<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\Form;

use Koh\WebMcp\Infrastructure\Form\FormSchemaMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FormSchemaMapperTest extends TestCase
{
    private FormSchemaMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FormSchemaMapper();
    }

    #[Test]
    public function mapEmptyFormReturnsObjectSchema(): void
    {
        $schema = $this->mapper->mapToJsonSchema(['renderables' => []]);

        self::assertSame('object', $schema['type']);
        self::assertSame([], $schema['properties']);
        self::assertArrayNotHasKey('required', $schema);
    }

    #[Test]
    public function mapTextFieldReturnsStringType(): void
    {
        $formDefinition = [
            'renderables' => [
                [
                    'renderables' => [
                        [
                            'identifier' => 'name',
                            'type' => 'Text',
                            'label' => 'Your Name',
                        ],
                    ],
                ],
            ],
        ];

        $schema = $this->mapper->mapToJsonSchema($formDefinition);

        self::assertSame('string', $schema['properties']['name']['type']);
        self::assertSame('Your Name', $schema['properties']['name']['description']);
    }

    #[Test]
    public function mapEmailFieldReturnsStringWithFormat(): void
    {
        $formDefinition = [
            'renderables' => [
                [
                    'renderables' => [
                        [
                            'identifier' => 'email',
                            'type' => 'EmailAddress',
                            'label' => 'Email',
                        ],
                    ],
                ],
            ],
        ];

        $schema = $this->mapper->mapToJsonSchema($formDefinition);

        self::assertSame('string', $schema['properties']['email']['type']);
        self::assertSame('email', $schema['properties']['email']['format']);
    }

    #[Test]
    public function mapSelectFieldReturnsEnum(): void
    {
        $formDefinition = [
            'renderables' => [
                [
                    'renderables' => [
                        [
                            'identifier' => 'color',
                            'type' => 'SingleSelect',
                            'label' => 'Color',
                            'properties' => [
                                'options' => [
                                    'red' => 'Red',
                                    'blue' => 'Blue',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $schema = $this->mapper->mapToJsonSchema($formDefinition);

        self::assertSame('string', $schema['properties']['color']['type']);
        self::assertSame(['red', 'blue'], $schema['properties']['color']['enum']);
    }

    #[Test]
    public function mapRequiredFieldAddsToRequired(): void
    {
        $formDefinition = [
            'renderables' => [
                [
                    'renderables' => [
                        [
                            'identifier' => 'name',
                            'type' => 'Text',
                            'label' => 'Name',
                            'validators' => [
                                ['identifier' => 'NotEmpty'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $schema = $this->mapper->mapToJsonSchema($formDefinition);

        self::assertContains('name', $schema['required']);
    }

    #[Test]
    public function mapCheckboxReturnsBooleanType(): void
    {
        $formDefinition = [
            'renderables' => [
                [
                    'renderables' => [
                        [
                            'identifier' => 'agree',
                            'type' => 'Checkbox',
                            'label' => 'I agree',
                        ],
                    ],
                ],
            ],
        ];

        $schema = $this->mapper->mapToJsonSchema($formDefinition);

        self::assertSame('boolean', $schema['properties']['agree']['type']);
    }

    #[Test]
    public function mapNumberFieldReturnsNumberType(): void
    {
        $formDefinition = [
            'renderables' => [
                [
                    'renderables' => [
                        [
                            'identifier' => 'age',
                            'type' => 'Number',
                            'label' => 'Age',
                        ],
                    ],
                ],
            ],
        ];

        $schema = $this->mapper->mapToJsonSchema($formDefinition);

        self::assertSame('number', $schema['properties']['age']['type']);
    }

    #[Test]
    public function mapMultiSelectReturnsArrayType(): void
    {
        $formDefinition = [
            'renderables' => [
                [
                    'renderables' => [
                        [
                            'identifier' => 'colors',
                            'type' => 'MultiSelect',
                            'label' => 'Colors',
                            'properties' => [
                                'options' => [
                                    'red' => 'Red',
                                    'blue' => 'Blue',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $schema = $this->mapper->mapToJsonSchema($formDefinition);

        self::assertSame('array', $schema['properties']['colors']['type']);
        self::assertSame(['red', 'blue'], $schema['properties']['colors']['items']['enum']);
    }
}
