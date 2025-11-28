<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Converted GenerateInternalsCommand to Symfony style.
 *
 * This file preserves the original generation logic (phpDocumentor, Smarty).
 * It expects phpDocumentor and Smarty available in your environment.
 */
class GenerateInternalsCommand extends Command
{
    protected static $defaultName = 'generate-internals';
    protected static $defaultDescription = 'generate the classes to provide wrappers to internal commands making them usable for scripting';

    public function snakeCase($className)
    {
        $className = lcfirst($className);
        $return = preg_replace_callback('/[A-Z]/', function ($matches) {
            return '_' . strtolower($matches[0]);
        }, $className);
        return $return;
    }

    public function kebabCase($className)
    {
        $className = lcfirst($className);
        $return = preg_replace_callback('/[A-Z]/', function ($matches) {
            return '-' . strtolower($matches[0]);
        }, $className);
        return $return;
    }

    public function pascalCase($command)
    {
        $args = explode('-', $command);
        foreach ($args as & $a) {
            $a = ucfirst($a);
        }
        return join('', $args);
    }

    public function camelCase($command)
    {
        $args = explode('-', $command);
        foreach ($args as $idx => & $a) {
            if ($idx > 0) {
                $a = ucfirst($a);
            }
        }
        return join('', $args);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $templateFile = __DIR__ . '/../Resources/InternalCommand.php.tpl';
        $templateClassFile = __DIR__ . '/../Resources/InternalCommandClass.php.tpl';
        $smarty = new \Smarty();
        $smarty->setTemplateDir(['.'])
            ->setCompileDir('/home/my/logs/smarty_templates_c')
            ->setConfigDir('/home/my/config/smarty_configs')
            ->setCacheDir('/home/my/logs/smarty_cache')
            ->setCaching(false);
        $smarty->setDebugging(false);
        $dirName = __DIR__ . '/InternalsCommand/';
        @mkdir($dirName, 0775, true);
        $files = [];
        foreach (array_merge(glob('app/Vps.php') ?: [], glob('app/Os/*.php') ?: [], glob('app/Vps/*.php') ?: []) as $fileName) {
            $files[] = new \phpDocumentor\Reflection\File\LocalFile($fileName);
        }
        $projectFactory = \phpDocumentor\Reflection\Php\ProjectFactory::createInstance();
        /** @var \phpDocumentor\Reflection\Php\Project $project */
        $project = $projectFactory->create('MyProject', $files);
        foreach ($project->getFiles() as $fileName => $file) {
            $output->writeln("File - {$fileName}");
            $smarty->assign('fileName', $fileName);
            foreach ($file->getClasses() as $classFullName => $class) {
                $className = $class->getFqsen()->getName();
                $output->writeln("- {$classFullName} ({$className})");
                $classAssign = [
                    'name' => $className,
                    'fullName' => $classFullName,
                ];
                $smarty->assign('class', $classAssign);
                $dirName = __DIR__ . '/InternalsCommand/' . $className . 'Command';
                file_put_contents($dirName . '.php', $smarty->fetch($templateClassFile));
                @mkdir($dirName, 0775, true);
                foreach ($class->getMethods() as $methodFullName => $method) {
                    $docblock = $method->getDocBlock();
                    $methodName = $method->getName();
                    $arguments = $method->getArguments();
                    $returnType = $method->getReturnType();
                    $output->writeln("  - {$methodName}");
                    $methodAssign = [
                        'name' => $methodName,
                        'pascal' => $this->pascalCase($methodName),
                        'camel' => $this->camelCase($methodName),
                        'snake' => $this->snakeCase($methodName),
                        'kebab' => $this->kebabCase($methodName),
                    ];
                    if (!is_null($docblock)) {
                        $description = $docblock->getDescription();
                        $summary = $docblock->getSummary();
                        $methodAssign['summary'] = $summary;
                        $output->writeln("    - description: {$description}");
                        $output->writeln("    - summary: {$summary}");
                        $returnTags = $docblock->getTagsByName('return');
                        if (count($returnTags) > 0) {
                            $returnType = $returnTags[0]->getType();
                            $methodAssign['returnType'] = $returnType;
                        }
                        $tags = $docblock->getTags();
                        foreach ($tags as $idx => $tag) {
                            $tagName = $tag->getName();
                            $tagType = $tag->getType();
                            $tagDesc = $tag->getDescription();
                            $descBody = method_exists($tagDesc, 'getBodyTemplate') ? $tagDesc->getBodyTemplate() : '';
                            $output->writeln("        Tag {$tagName} type {$tagType}");
                            $output->writeln("        tag desc body template {$descBody}");
                        }
                    }
                    $smarty->assign('class', $classAssign);
                    $smarty->assign('method', $methodAssign);
                    file_put_contents($dirName . '/' . $this->pascalCase($methodName) . 'Command.php', $smarty->fetch($templateFile));
                }
            }
        }

        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }
}
