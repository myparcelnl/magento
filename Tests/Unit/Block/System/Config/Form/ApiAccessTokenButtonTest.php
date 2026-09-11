<?php

declare(strict_types=1);

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Block\System\Config\Form\ApiAccessTokenButton;

/**
 * Subclass exposing a stub getCurrentScope() so we can exercise getScopeComment()
 * without standing up a full Backend block Context.
 */
final class FakeApiAccessTokenButton extends ApiAccessTokenButton
{
    /** @var array{0: string, 1: int} */
    private array $stubScope;

    public function __construct(array $stubScope)
    {
        $this->stubScope = $stubScope;
        // intentionally skip parent::__construct — getScopeComment only needs getCurrentScope.
    }

    public function getCurrentScope(): array
    {
        return $this->stubScope;
    }
}

it('returns the default-scope comment when admin scope is default', function () {
    $block = new FakeApiAccessTokenButton([ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0]);

    expect($block->getScopeComment())
        ->toBe('Covers every store not tokened separately at website or store-view scope.');
});

it('returns the website-scope comment that warns about removal from the default-scope token view', function () {
    $block = new FakeApiAccessTokenButton([ScopeInterface::SCOPE_WEBSITES, 1]);

    $comment = $block->getScopeComment();

    expect($comment)->toStartWith('Covers every store-view in this website not tokened separately at store-view scope.');
    expect($comment)->toContain("removes these stores from the default-scope token's view");
    expect($comment)->toContain('any store-view in this website with its own');
});

it('returns the store-view-scope comment that warns about removal from default AND parent-website token views', function () {
    $block = new FakeApiAccessTokenButton([ScopeInterface::SCOPE_STORES, 2]);

    $comment = $block->getScopeComment();

    expect($comment)->toStartWith('Covers only this store.');
    expect($comment)->toContain("removes this store from the default-scope token's view");
    expect($comment)->toContain("any parent-website token's view");
});
