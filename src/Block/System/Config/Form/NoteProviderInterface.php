<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

/**
 * A dynamic settings `frontend_model` whose note is only known at render time.
 *
 * The block returns the text instead of printing it, so `dynamic_settings.phtml` can keep the
 * tooltip the control's immediate sibling. A note printed inside the control slot is a block box
 * ahead of the right-floated tooltip, which then cannot rise back beside the input.
 */
interface NoteProviderInterface
{
    public function getNote(): string;
}
