<?php

declare (strict_types=1);
/*
 * This file is part of the SymfonyCasts DynamicForms package.
 * Copyright (c) SymfonyCasts <https://symfonycasts.com/>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Symfonycasts\Dynamic_Forms;

/**
 * Used to configure a dependent/dynamic field.
 *
 * If ->add() is not called, the field won't be included.
 */
class Dependent_Field
{
    private ?string $type = null;
    private array $options = [];
    private bool $should_be_added = false;
    public function add(?string $type = null, array $options = []): static
    {
        $this->type = $type;
        $this->options = $options;
        $this->should_be_added = true;
        return $this;
    }
    public function get_type(): ?string
    {
        return $this->type;
    }
    public function get_options(): array
    {
        return $this->options;
    }
    public function should_be_added(): bool
    {
        return $this->should_be_added;
    }
}