<?php

declare (strict_types=1);
/*
 * This file is part of the SymfonyCasts DynamicForms package.
 * Copyright (c) SymfonyCasts <https://symfonycasts.com/>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Symfonycasts\Dynamic_Forms;

use Symfony\Component\Form\Form_Events;
/**
 * Holds the configuration for a dynamic field & what listeners have been executed.
 */
class Dependent_Field_Config
{
    public array $callback_executed = [Form_Events::PRE_SET_DATA => false, Form_Events::POST_SUBMIT => false];
    public function __construct(public string $name, public array $dependencies, public \Closure $callback)
    {
    }
    public function is_ready(array $available_dependency_data, string $event_name): bool
    {
        if (!\array_key_exists($event_name, $this->callback_executed)) {
            throw new \InvalidArgumentException(\sprintf('Invalid event name "%s"', $event_name));
        }
        if ($this->callback_executed[$event_name]) {
            return false;
        }
        foreach ($this->dependencies as $dependency) {
            if (!\array_key_exists($dependency, $available_dependency_data)) {
                return false;
            }
        }
        return true;
    }
    public function execute(array $available_dependency_data, string $event_name): Dependent_Field
    {
        $configurable_form_builder = new Dependent_Field();
        $this->callback_executed[$event_name] = true;
        $dependency_data = array_map(static fn(string $dependency) => $available_dependency_data[$dependency], $this->dependencies);
        $this->callback->__invoke($configurable_form_builder, ...$dependency_data);
        return $configurable_form_builder;
    }
}