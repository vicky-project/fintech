<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Contracts\BankParserInterface;

class BankParserManager
{
  protected array $parsers = [];

  public function __construct(array $parsers = []) {
    $this->parsers = $parsers;
  }

  public function addParser(BankParserInterface $parser): void
  {
    $this->parsers[] = $parser;
  }

  public function parse(string $filePath): array
  {
    foreach ($this->parsers as $parser) {
      if ($parser->canParse($filePath)) {
        return [
          'bank_code' => $parser->getBankCode(),
          'transactions' => $parser->parse($filePath),
          'currency' => $parser->getCurrency()
        ];
      }
    }

    throw new \Exception("Format statement bank tidak dikenali.");
  }
}