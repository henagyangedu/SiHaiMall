<?php

namespace App\Models;

/** 税率规则：一个国家（或其中一个省 / 州）一条。怎么匹配、怎么算见 Common\TaxService */
class TaxRate extends BaseModel
{
    protected $table = 'tax_rate';
}
