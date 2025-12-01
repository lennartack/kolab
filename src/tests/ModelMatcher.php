<?php

namespace Tests;

use Mockery\Matcher\MatcherAbstract;

class ModelMatcher extends MatcherAbstract implements \Stringable
{
    public function match(&$actual)
    {
        return $this->_expected->is($actual); // @phpstan-ignore-line
    }

    public function __toString(): string
    {
        return '<Model>';
    }
}
