<?php

declare(strict_types=1);

namespace WsdlToPhp\PackageGenerator\File;

use InvalidArgumentException;
use WsdlToPhp\PackageGenerator\Container\PhpElement\Method;
use WsdlToPhp\PackageGenerator\Container\PhpElement\Property;
use WsdlToPhp\PackageGenerator\Container\PhpElement\Constant;
use WsdlToPhp\PackageGenerator\Model\Struct as StructModel;
use WsdlToPhp\PackageGenerator\Model\StructAttribute as StructAttributeModel;
use WsdlToPhp\PackageGenerator\Model\AbstractModel;
use WsdlToPhp\PackageGenerator\File\Utils as FileUtils;
use WsdlToPhp\PackageGenerator\Generator\Utils as GeneratorUtils;
use WsdlToPhp\PhpGenerator\Element\PhpAnnotationBlock;
use WsdlToPhp\PhpGenerator\Element\PhpAnnotation;
use WsdlToPhp\PhpGenerator\Element\PhpDeclare;
use WsdlToPhp\PhpGenerator\Element\PhpMethod;
use WsdlToPhp\PhpGenerator\Element\PhpProperty;
use WsdlToPhp\PhpGenerator\Element\PhpConstant;
use WsdlToPhp\PhpGenerator\Component\PhpClass;
use WsdlToPhp\PackageGenerator\ConfigurationReader\XsdTypes;

abstract class AbstractModelFile extends AbstractFile
{
    public const ANNOTATION_META_LENGTH = 250;
    public const ANNOTATION_LONG_LENGTH = 1000;
    public const ANNOTATION_PACKAGE = 'package';
    public const ANNOTATION_SUB_PACKAGE = 'subpackage';
    public const ANNOTATION_RETURN = 'return';
    public const ANNOTATION_USES = 'uses';
    public const ANNOTATION_PARAM = 'param';
    public const ANNOTATION_VAR = 'var';
    public const ANNOTATION_SEE = 'see';
    public const ANNOTATION_THROWS = 'throws';
    public const METHOD_CONSTRUCT = '__construct';
    public const TYPE_ARRAY = 'array';
    public const TYPE_BOOL = 'bool';
    public const TYPE_STRING = 'string';

    private ?AbstractModel $model = null;

    protected Method $methods;

    public function getFileDestination(bool $withSrc = true): string
    {
        return sprintf('%s%s%s', $this->getDestinationFolder($withSrc), $this->getModel()->getSubDirectory(), !empty($this->getModel()->getSubDirectory()) ? '/' : '');
    }

    public function getDestinationFolder(bool $withSrc = true): string
    {
        $src = rtrim($this->generator->getOptionSrcDirname(), DIRECTORY_SEPARATOR);
        return sprintf('%s%s', $this->getGenerator()->getOptionDestination(), (bool) $withSrc && !empty($src) ? $src . DIRECTORY_SEPARATOR : '');
    }

    public function writeFile(bool $withSrc = true): void
    {
        if (!$this->getModel()) {
            throw new InvalidArgumentException('You MUST define the model before being able to generate the file', __LINE__);
        }
        GeneratorUtils::createDirectory($this->getFileDestination($withSrc));
        $this
            ->addDeclareDirective()
            ->defineNamespace()
            ->defineUseStatement()
            ->addAnnotationBlock()
            ->addClassElement();
        parent::writeFile();
    }

    protected function addAnnotationBlock(): AbstractModelFile
    {
        $this->getFile()->addAnnotationBlockElement($this->getClassAnnotationBlock());

        return $this;
    }

    public function setModel(AbstractModel $model): self
    {
        $this->model = $model;

        $this
            ->getFile()
            ->getMainElement()
            ->setName($model->getPackagedName());

        return $this;
    }

    public function getModel(): ?AbstractModel
    {
        return $this->model;
    }

    protected function getModelByName(string $name): ?StructModel
    {
        return $this->getGenerator()->getStructByName($name);
    }

    protected function definePackageAnnotations(PhpAnnotationBlock $block): self
    {
        $packageName = $this->getPackageName();
        if (!empty($packageName)) {
            $block->addChild(new PhpAnnotation(self::ANNOTATION_PACKAGE, $packageName));
        }
        if (count($this->getModel()->getDocSubPackages()) > 0) {
            $block->addChild(new PhpAnnotation(self::ANNOTATION_SUB_PACKAGE, implode(',', $this->getModel()->getDocSubPackages())));
        }

        return $this;
    }

    protected function getPackageName(): string
    {
        $packageName = '';
        if (!empty($this->getGenerator()->getOptionPrefix())) {
            $packageName = $this->getGenerator()->getOptionPrefix();
        } elseif (!empty($this->getGenerator()->getOptionSuffix())) {
            $packageName = $this->getGenerator()->getOptionSuffix();
        }

        return $packageName;
    }

    protected function defineGeneralAnnotations(PhpAnnotationBlock $block): self
    {
        foreach ($this->getGenerator()->getOptionAddComments() as $tagName => $tagValue) {
            $block->addChild(new PhpAnnotation($tagName, $tagValue));
        }

        return $this;
    }

    protected function getClassAnnotationBlock(): PhpAnnotationBlock
    {
        $block = new PhpAnnotationBlock();
        $block->addChild($this->getClassDeclarationLine());
        $this->defineModelAnnotationsFromWsdl($block)->definePackageAnnotations($block)->defineGeneralAnnotations($block);

        return $block;
    }

    protected function getClassDeclarationLine(): string
    {
        return sprintf($this->getClassDeclarationLineText(), $this->getModel()->getName(), $this->getModel()->getContextualPart());
    }

    protected function getClassDeclarationLineText(): string
    {
        return 'This class stands for %s %s';
    }

    protected function defineModelAnnotationsFromWsdl(PhpAnnotationBlock $block, AbstractModel $model = null): self
    {
        FileUtils::defineModelAnnotationsFromWsdl($block, $model instanceof AbstractModel ? $model : $this->getModel());

        return $this;
    }

    protected function addClassElement(): AbstractModelFile
    {
        $class = new PhpClass($this->getModel()->getPackagedName(), $this->getModel()->isAbstract(), '' === $this->getModel()->getExtendsClassName() ? null : $this->getModel()->getExtendsClassName());
        $this
            ->defineConstants($class)
            ->defineProperties($class)
            ->defineMethods($class)
            ->getFile()
            ->addClassComponent($class);

        return $this;
    }

    protected function addDeclareDirective(): self
    {
        $this->getFile()->setDeclare(PhpDeclare::DIRECTIVE_STRICT_TYPES, 1);

        return $this;
    }

    protected function defineNamespace(): self
    {
        if (!empty($this->getModel()->getNamespace())) {
            $this->getFile()->setNamespace($this->getModel()->getNamespace());
        }

        return $this;
    }

    protected function defineUseStatement(): self
    {
        if (!empty($this->getModel()->getExtends())) {
            $this->getFile()->addUse($this->getModel()->getExtends(), null, true);
        }

        return $this;
    }

    protected function defineConstants(PhpClass $class): self
    {
        $constants = new Constant($this->getGenerator());
        $this->fillClassConstants($constants);
        foreach ($constants as $constant) {
            $annotationBlock = $this->getConstantAnnotationBlock($constant);
            if (!empty($annotationBlock)) {
                $class->addAnnotationBlockElement($annotationBlock);
            }
            $class->addConstantElement($constant);
        }

        return $this;
    }

    protected function defineProperties(PhpClass $class): self
    {
        $properties = new Property($this->getGenerator());
        $this->fillClassProperties($properties);
        foreach ($properties as $property) {
            $annotationBlock = $this->getPropertyAnnotationBlock($property);
            if (!empty($annotationBlock)) {
                $class->addAnnotationBlockElement($annotationBlock);
            }
            $class->addPropertyElement($property);
        }

        return $this;
    }

    protected function defineMethods(PhpClass $class): self
    {
        $this->methods = new Method($this->getGenerator());
        $this->fillClassMethods();
        foreach ($this->methods as $method) {
            $annotationBlock = $this->getMethodAnnotationBlock($method);
            if (!empty($annotationBlock)) {
                $class->addAnnotationBlockElement($annotationBlock);
            }
            $class->addMethodElement($method);
        }

        return $this;
    }

    abstract protected function fillClassConstants(Constant $constants): void;

    abstract protected function getConstantAnnotationBlock(PhpConstant $constant): ?PhpAnnotationBlock;

    abstract protected function fillClassProperties(Property $properties): void;

    abstract protected function getPropertyAnnotationBlock(PhpProperty $property): ?PhpAnnotationBlock;

    abstract protected function fillClassMethods(): void;

    abstract protected function getMethodAnnotationBlock(PhpMethod $method): ?PhpAnnotationBlock;

    protected function getStructAttribute(StructAttributeModel $attribute = null): ?StructAttributeModel
    {
        $struct = $this->getModel();
        if (empty($attribute) && $struct instanceof StructModel && 1 === $struct->getAttributes()->count()) {
            $attribute = $struct->getAttributes()->offsetGet(0);
        }

        return $attribute;
    }

    public function getModelFromStructAttribute(StructAttributeModel $attribute = null): ?StructModel
    {
        return $this->getStructAttribute($attribute)->getTypeStruct();
    }

    public function getRestrictionFromStructAttribute(StructAttributeModel $attribute = null): ?StructModel
    {
        $model = $this->getModelFromStructAttribute($attribute);
        if ($model instanceof StructModel) {
            // list are mainly scalar values of basic types (string, int, etc.) or of Restriction values
            if ($model->isList()) {
                $subModel = $this->getModelByName($model->getList());
                if ($subModel && $subModel->isRestriction()) {
                    $model = $subModel;
                } elseif (!$model->isRestriction()) {
                    $model = null;
                }
            } elseif (!$model->isRestriction()) {
                $model = null;
            }
        }

        return $model;
    }

    public function isAttributeAList(StructAttributeModel $attribute = null): bool
    {
        return $this->getStructAttribute($attribute)->isList();
    }

    public function getStructAttributeType(StructAttributeModel $attribute = null, bool $namespaced = false): string
    {
        $attribute = $this->getStructAttribute($attribute);
        $inheritance = $attribute->getInheritance();
        $type = empty($inheritance) ? $attribute->getType() : $inheritance;

        if (!empty($type) && ($struct = $this->getGenerator()->getStructByName($type))) {
            $inheritance = $struct->getTopInheritance();
            if (!empty($inheritance)) {
                $type = str_replace('[]', '', $inheritance);
            } else {
                $type = $struct->getPackagedName($namespaced);
            }
        }

        $model = $this->getModelFromStructAttribute($attribute);
        if ($model instanceof StructModel) {
            // issue #84: union is considered as string as it would be difficult to have a method that accepts multiple object types.
            // If the property has to be an object of multiple types => new issue...
            if ($model->isRestriction() || $model->isUnion()) {
                $type = self::TYPE_STRING;
            } elseif ($model->isStruct()) {
                $type = $model->getPackagedName($namespaced);
            } elseif ($model->isArray() && ($inheritanceStruct = $model->getInheritanceStruct()) instanceof StructModel) {
                $type = $inheritanceStruct->getPackagedName($namespaced);
            }
        }

        return $type;
    }

    protected function getStructAttributeTypeGetAnnotation(StructAttributeModel $attribute = null, bool $returnArrayType = true): string
    {
        $attribute = $this->getStructAttribute($attribute);

        if ($attribute->isXml()) {
            return '\\DOMDocument|string|null';
        }

        return sprintf('%s%s%s', $this->getStructAttributeTypeAsPhpType($attribute), $this->useBrackets($attribute, $returnArrayType) ? '[]' : '', $attribute->isRequired() ? '' : '|null');
    }

    protected function getStructAttributeTypeSetAnnotation(StructAttributeModel $attribute = null, bool $returnArrayType = true): string
    {
        $attribute = $this->getStructAttribute($attribute);
        return sprintf('%s%s', $this->getStructAttributeTypeAsPhpType($attribute), $this->useBrackets($attribute, $returnArrayType) ? '[]' : '');
    }

    protected function useBrackets(StructAttributeModel $attribute, bool $returnArrayType = true): bool
    {
        return $returnArrayType && ($attribute->isArray() || $this->isAttributeAList($attribute));
    }

    protected function getStructAttributeTypeHint(StructAttributeModel $attribute = null, bool $returnArrayType = true): string
    {
        $attribute = $this->getStructAttribute($attribute);

        return ($returnArrayType && ($attribute->isArray() || $this->isAttributeAList($attribute))) ? self::TYPE_ARRAY : $this->getStructAttributeType($attribute, true);
    }

    public function getStructAttributeTypeAsPhpType(StructAttributeModel $attribute = null): string
    {
        $attribute = $this->getStructAttribute($attribute);
        $attributeType = $this->getStructAttributeType($attribute, true);
        if (XsdTypes::instance($this->getGenerator()->getOptionXsdTypesPath())->isXsd($attributeType)) {
            $attributeType = self::getPhpType($attributeType, $this->getGenerator()->getOptionXsdTypesPath());
        }

        return $attributeType;
    }

    /**
     * See http://php.net/manual/fr/language.oop5.typehinting.php for these cases
     * Also see http://www.w3schools.com/schema/schema_dtypes_numeric.asp
     * @param mixed $type
     * @param null $xsdTypesPath
     * @param mixed $fallback
     * @return mixed
     */
    public static function getValidType($type, $xsdTypesPath = null, $fallback = null)
    {
        return XsdTypes::instance($xsdTypesPath)->isXsd(str_replace('[]', '', $type)) ? $fallback : $type;
    }

    /**
     * See http://php.net/manual/fr/language.oop5.typehinting.php for these cases
     * Also see http://www.w3schools.com/schema/schema_dtypes_numeric.asp
     * @param mixed $type
     * @param null $xsdTypesPath
     * @param mixed $fallback
     * @return mixed
     */
    public static function getPhpType($type, $xsdTypesPath = null, $fallback = self::TYPE_STRING)
    {
        return XsdTypes::instance($xsdTypesPath)->isXsd(str_replace('[]', '', $type)) ? XsdTypes::instance($xsdTypesPath)->phpType($type) : $fallback;
    }
}
