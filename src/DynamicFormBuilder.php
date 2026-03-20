<?php

declare (strict_types=1);
/*
 * This file is part of the SymfonyCasts DynamicForms package.
 * Copyright (c) SymfonyCasts <https://symfonycasts.com/>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Symfonycasts\Dynamic_Forms;

use Symfony\Component\Event_Dispatcher\Event_Dispatcher_Interface;
use Symfony\Component\Event_Dispatcher\Event_Subscriber_Interface;
use Symfony\Component\Form\Clearable_Errors_Interface;
use Symfony\Component\Form\Data_Mapper_Interface;
use Symfony\Component\Form\Data_Transformer_Interface;
use Symfony\Component\Form\Extension\Core\Type\Hidden_Type;
use Symfony\Component\Form\Form_Builder_Interface;
use Symfony\Component\Form\Form_Config_Interface;
use Symfony\Component\Form\Form_Error;
use Symfony\Component\Form\Form_Event;
use Symfony\Component\Form\Form_Events;
use Symfony\Component\Form\Form_Factory_Interface;
use Symfony\Component\Form\Form_Interface;
use Symfony\Component\Form\Request_Handler_Interface;
use Symfony\Component\Form\Resolved_Form_Type_Interface;
use Symfony\Component\Property_Access\Property_Path_Interface;
/**
 * Wraps the normal form builder & to add addDynamic() to it.
 *
 * @author Ryan Weaver
 */
class Dynamic_Form_Builder implements Form_Builder_Interface, \IteratorAggregate
{
    /**
     * @var DependentFieldConfig[]
     */
    private array $dependent_field_configs = [];
    /**
     * The actual form that this builder is turned into.
     */
    private Form_Interface $form;
    private array $pre_set_data_dependency_data = [];
    private array $post_submit_dependency_data = [];
    public function __construct(private readonly Form_Builder_Interface $builder)
    {
        $builder->add_event_listener(Form_Events::PRE_SET_DATA, function (Form_Event $event): void {
            $this->form = $event->get_form();
            $this->pre_set_data_dependency_data = [];
            $this->initialize_listeners();
            // A fake hidden field where we can "store" an error if a dependent form
            // field is suddenly invalid because its previous data was invalid
            // and a field it depends on just changed (e.g. user selected "Michigan"
            // as a state, then the user changed "Country" from "USA" to "Mexico"
            // and so now "Michigan" is invalid). In this case, we clear the error
            // on the actual field, but store a "fake" error here, which won't be
            // rendered, but will prevent the form from being valid.
            if (!$this->form->has('__dynamic_error')) {
                $this->form->add('__dynamic_error', Hidden_Type::class, ['mapped' => false, 'error_bubbling' => false]);
            }
        }, 100);
        $builder->add_event_listener(Form_Events::POST_SUBMIT, function (Form_Event $event): void {
            $this->post_submit_dependency_data = [];
        });
        // guarantee later than core ValidationListener
        $builder->add_event_listener(Form_Events::POST_SUBMIT, function (Form_Event $event): void {
            $this->clear_data_on_transformation_error($event);
        }, -1);
    }
    public function add_dependent(string $name, string|array $dependencies, callable $callback): self
    {
        $dependencies = (array) $dependencies;
        $this->dependent_field_configs[] = new Dependent_Field_Config($name, $dependencies, $callback);
        return $this;
    }
    public function store_pre_set_data_dependency_data(Form_Event $event): void
    {
        $dependency = $event->get_form()->get_name();
        $this->pre_set_data_dependency_data[$dependency] = $event->get_data();
        $this->execute_ready_callbacks($this->pre_set_data_dependency_data, Form_Events::PRE_SET_DATA);
    }
    public function store_post_submit_dependency_data(Form_Event $event): void
    {
        $dependency = $event->get_form()->get_name();
        $this->post_submit_dependency_data[$dependency] = $event->get_form()->get_data();
        $this->execute_ready_callbacks($this->post_submit_dependency_data, Form_Events::POST_SUBMIT);
    }
    public function clear_data_on_transformation_error(Form_Event $event): void
    {
        $form = $event->get_form();
        $transformation_errors_cleared = false;
        foreach ($this->dependent_field_configs as $dependent_field_config) {
            if (!$form->has($dependent_field_config->name)) {
                continue;
            }
            $sub_form = $form->get($dependent_field_config->name);
            if ($sub_form->get_transformation_failure() && $sub_form instanceof Clearable_Errors_Interface) {
                $sub_form->clear_errors();
                $transformation_errors_cleared = true;
            }
        }
        if ($transformation_errors_cleared) {
            // We've cleared the error, but the bad data remains on the field.
            // We need to make sure that the form doesn't submit successfully,
            // but we also don't want to render a validation error on any field.
            // So, we jam the error into a hidden field, which doesn't render errors.
            if ($form->get('__dynamic_error')->is_valid()) {
                $form->get('__dynamic_error')->add_error(new Form_Error('Some dynamic fields have errors.'));
            }
        }
    }
    private function execute_ready_callbacks(array $available_dependency_data, string $event_name): void
    {
        foreach ($this->dependent_field_configs as $dependent_field_config) {
            if ($dependent_field_config->is_ready($available_dependency_data, $event_name)) {
                $dynamic_field = $dependent_field_config->execute($available_dependency_data, $event_name);
                $name = $dependent_field_config->name;
                if (!$dynamic_field->should_be_added()) {
                    $this->form->remove($name);
                    continue;
                }
                $this->builder->add($name, $dynamic_field->get_type(), $dynamic_field->get_options());
                $this->initialize_listeners([$name]);
                // auto initialize mimics FormBuilder::getForm() behavior
                $field = $this->builder->get($name)->set_auto_initialize(false)->get_form();
                $this->form->add($field);
            }
        }
    }
    private function initialize_listeners(?array $fields_to_consider = null): void
    {
        $registered_fields = [];
        foreach ($this->dependent_field_configs as $dynamic_field) {
            foreach ($dynamic_field->dependencies as $dependency) {
                if ($fields_to_consider && !\in_array($dependency, $fields_to_consider)) {
                    continue;
                }
                // skip dependencies that are possibly not *yet* part of the form
                if (!$this->builder->has($dependency)) {
                    continue;
                }
                if (\in_array($dependency, $registered_fields)) {
                    continue;
                }
                $registered_fields[] = $dependency;
                $this->builder->get($dependency)->add_event_listener(Form_Events::PRE_SET_DATA, $this->store_pre_set_data_dependency_data(...));
                $this->builder->get($dependency)->add_event_listener(Form_Events::POST_SUBMIT, $this->store_post_submit_dependency_data(...));
            }
        }
    }
    /*
     * ----------------------------------------
     *
     * Pure decoration methods below.
     *
     * ----------------------------------------
     */
    public function count(): int
    {
        return $this->builder->count();
    }
    /**
     * @param string|FormBuilderInterface $child
     */
    public function add($child, ?string $type = null, array $options = []): static
    {
        $this->builder->add($child, $type, $options);
        return $this;
    }
    public function create(string $name, ?string $type = null, array $options = []): Form_Builder_Interface
    {
        return $this->builder->create($name, $type, $options);
    }
    public function get(string $name): Form_Builder_Interface
    {
        return $this->builder->get($name);
    }
    public function remove(string $name): static
    {
        $this->builder->remove($name);
        return $this;
    }
    public function has(string $name): bool
    {
        return $this->builder->has($name);
    }
    public function all(): array
    {
        return $this->builder->all();
    }
    public function get_form(): Form_Interface
    {
        return $this->builder->get_form();
    }
    public function add_event_listener(string $event_name, callable $listener, int $priority = 0): static
    {
        $this->builder->add_event_listener($event_name, $listener, $priority);
        return $this;
    }
    public function add_event_subscriber(Event_Subscriber_Interface $subscriber): static
    {
        $this->builder->add_event_subscriber($subscriber);
        return $this;
    }
    public function add_view_transformer(Data_Transformer_Interface $view_transformer, bool $force_prepend = false): static
    {
        $this->builder->add_view_transformer($view_transformer, $force_prepend);
        return $this;
    }
    public function reset_view_transformers(): static
    {
        $this->builder->reset_view_transformers();
        return $this;
    }
    public function add_model_transformer(Data_Transformer_Interface $model_transformer, bool $force_append = false): static
    {
        $this->builder->add_model_transformer($model_transformer, $force_append);
        return $this;
    }
    public function reset_model_transformers(): static
    {
        $this->builder->reset_model_transformers();
        return $this;
    }
    public function set_attribute(string $name, mixed $value): static
    {
        $this->builder->set_attribute($name, $value);
        return $this;
    }
    public function set_attributes(array $attributes): static
    {
        $this->builder->set_attributes($attributes);
        return $this;
    }
    public function set_data_mapper(?Data_Mapper_Interface $data_mapper = null): static
    {
        $this->builder->set_data_mapper($data_mapper);
        return $this;
    }
    public function set_disabled(bool $disabled): static
    {
        $this->builder->set_disabled($disabled);
        return $this;
    }
    public function set_empty_data(mixed $empty_data): static
    {
        $this->builder->set_empty_data($empty_data);
        return $this;
    }
    public function set_error_bubbling(bool $error_bubbling): static
    {
        $this->builder->set_error_bubbling($error_bubbling);
        return $this;
    }
    public function set_inherit_data(bool $inherit_data): static
    {
        $this->builder->set_inherit_data($inherit_data);
        return $this;
    }
    public function set_mapped(bool $mapped): static
    {
        $this->builder->set_mapped($mapped);
        return $this;
    }
    public function set_method(string $method): static
    {
        $this->builder->set_method($method);
        return $this;
    }
    /**
     * @param string|PropertyPathInterface|null $propertyPath
     */
    public function set_property_path($property_path): static
    {
        $this->builder->set_property_path($property_path);
        return $this;
    }
    public function set_required(bool $required): static
    {
        $this->builder->set_required($required);
        return $this;
    }
    public function set_action(?string $action): static
    {
        $this->builder->set_action($action);
        return $this;
    }
    public function set_compound(bool $compound): static
    {
        $this->builder->set_compound($compound);
        return $this;
    }
    public function set_data_locked(bool $locked): static
    {
        $this->builder->set_data_locked($locked);
        return $this;
    }
    public function set_form_factory(Form_Factory_Interface $form_factory): static
    {
        $this->builder->set_form_factory($form_factory);
        return $this;
    }
    public function set_type(?Resolved_Form_Type_Interface $type): static
    {
        $this->builder->set_type($type);
        return $this;
    }
    public function set_request_handler(?Request_Handler_Interface $request_handler): static
    {
        $this->builder->set_request_handler($request_handler);
        return $this;
    }
    public function get_attribute(string $name, mixed $default = null): mixed
    {
        return $this->builder->get_attribute($name, $default);
    }
    public function has_attribute(string $name): bool
    {
        return $this->builder->has_attribute($name);
    }
    public function get_attributes(): array
    {
        return $this->builder->get_attributes();
    }
    public function get_data_mapper(): ?Data_Mapper_Interface
    {
        return $this->builder->get_data_mapper();
    }
    public function get_event_dispatcher(): Event_Dispatcher_Interface
    {
        return $this->builder->get_event_dispatcher();
    }
    public function get_name(): string
    {
        return $this->builder->get_name();
    }
    public function get_property_path(): ?Property_Path_Interface
    {
        return $this->builder->get_property_path();
    }
    public function get_request_handler(): Request_Handler_Interface
    {
        return $this->builder->get_request_handler();
    }
    public function get_type(): Resolved_Form_Type_Interface
    {
        return $this->builder->get_type();
    }
    public function set_by_reference(bool $by_reference): static
    {
        $this->builder->set_by_reference($by_reference);
        return $this;
    }
    public function set_data(mixed $data): static
    {
        $this->builder->set_data($data);
        return $this;
    }
    public function set_auto_initialize(bool $initialize): static
    {
        $this->builder->set_auto_initialize($initialize);
        return $this;
    }
    public function get_form_config(): Form_Config_Interface
    {
        return $this->builder->get_form_config();
    }
    public function set_is_empty_callback(?callable $is_empty_callback): static
    {
        $this->builder->set_is_empty_callback($is_empty_callback);
        return $this;
    }
    public function get_mapped(): bool
    {
        return $this->builder->get_mapped();
    }
    public function get_by_reference(): bool
    {
        return $this->builder->get_by_reference();
    }
    public function get_inherit_data(): bool
    {
        return $this->builder->get_inherit_data();
    }
    public function get_compound(): bool
    {
        return $this->builder->get_compound();
    }
    public function get_view_transformers(): array
    {
        return $this->builder->get_view_transformers();
    }
    public function get_model_transformers(): array
    {
        return $this->builder->get_model_transformers();
    }
    public function get_required(): bool
    {
        return $this->builder->get_required();
    }
    public function get_disabled(): bool
    {
        return $this->builder->get_disabled();
    }
    public function get_error_bubbling(): bool
    {
        return $this->builder->get_error_bubbling();
    }
    public function get_empty_data(): mixed
    {
        return $this->builder->get_empty_data();
    }
    public function get_data(): mixed
    {
        return $this->builder->get_data();
    }
    public function get_data_class(): ?string
    {
        return $this->builder->get_data_class();
    }
    public function get_data_locked(): bool
    {
        return $this->builder->get_data_locked();
    }
    public function get_form_factory(): Form_Factory_Interface
    {
        return $this->builder->get_form_factory();
    }
    public function get_action(): string
    {
        return $this->builder->get_action();
    }
    public function get_method(): string
    {
        return $this->builder->get_method();
    }
    public function get_auto_initialize(): bool
    {
        return $this->builder->get_auto_initialize();
    }
    public function get_options(): array
    {
        return $this->builder->get_options();
    }
    public function has_option(string $name): bool
    {
        return $this->builder->has_option($name);
    }
    public function get_option(string $name, mixed $default = null): mixed
    {
        return $this->builder->get_option($name, $default);
    }
    public function get_is_empty_callback(): ?callable
    {
        return $this->builder->get_is_empty_callback();
    }
    public function getIterator(): \Traversable
    {
        return $this->builder;
    }
}