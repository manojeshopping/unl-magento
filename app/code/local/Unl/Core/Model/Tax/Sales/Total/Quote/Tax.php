<?php

class Unl_Core_Model_Tax_Sales_Total_Quote_Tax extends Mage_Tax_Model_Sales_Total_Quote_Tax
{
    /* Override the logic of
     * @see Mage_Tax_Model_Sales_Total_Quote_Tax::_calculateShippingTax()
     * to allow the tax calculation amounts to be saved and to add custom logic for tax free items
     */
    protected function _calculateShippingTax(Mage_Sales_Model_Quote_Address $address, $taxRateRequest)
    {
        // custom logic for tax free items
        $itemTaxAmount = 0;
        foreach ($address->getAllItems() as $item) {
            if ($item->getTaxAmount()) {
                $itemTaxAmount += $item->getTaxAmount();
            }
        }
        if ($itemTaxAmount) {
            $taxRateRequest->setProductClassId($this->_config->getShippingTaxClass($this->_store));
        } else {
            $taxRateRequest->setProductClassId($this->_config->getExemptShippingTaxClass($this->_store));
        }
        // end

        $rate           = $this->_calculator->getRate($taxRateRequest);
        $inclTax        = $address->getIsShippingInclTax();
        $shipping       = $address->getShippingTaxable();
        $baseShipping   = $address->getBaseShippingTaxable();
        $rateKey        = (string)$rate;

        $hiddenTax      = null;
        $baseHiddenTax  = null;
        switch ($this->_helper->getCalculationSequence($this->_store)) {
            case Mage_Tax_Model_Calculation::CALC_TAX_BEFORE_DISCOUNT_ON_EXCL:
            case Mage_Tax_Model_Calculation::CALC_TAX_BEFORE_DISCOUNT_ON_INCL:
                $calc     = $shipping;
                $baseCalc = $baseShipping;
                break;
            case Mage_Tax_Model_Calculation::CALC_TAX_AFTER_DISCOUNT_ON_EXCL:
            case Mage_Tax_Model_Calculation::CALC_TAX_AFTER_DISCOUNT_ON_INCL:
                $discountAmount     = $address->getShippingDiscountAmount();
                $baseDiscountAmount = $address->getBaseShippingDiscountAmount();
                $calc     = $shipping - $discountAmount;
                $baseCalc = $baseShipping - $baseDiscountAmount;
                break;
        }

        $tax     = $this->_calculator->calcTaxAmount($calc, $rate, $inclTax, false);
        $baseTax = $this->_calculator->calcTaxAmount($baseCalc, $rate, $inclTax, false);

        if ($this->_config->getAlgorithm($this->_store) == Mage_Tax_Model_Calculation::CALC_TOTAL_BASE) {
            $tax        = $this->_deltaRound($tax, $rate, $inclTax);
            $baseTax    = $this->_deltaRound($baseTax, $rate, $inclTax, 'base');
        } else {
            $tax        = $this->_calculator->round($tax);
            $baseTax    = $this->_calculator->round($baseTax);
        }

        if ($inclTax && !empty($discountAmount)) {
            $hiddenTax      = $this->_calculator->calcTaxAmount($discountAmount, $rate, $inclTax, false);
            $baseHiddenTax  = $this->_calculator->calcTaxAmount($baseDiscountAmount, $rate, $inclTax, false);
            $this->_hiddenTaxes[] = array(
                'rate_key'   => $rateKey,
                'value'      => $hiddenTax,
                'base_value' => $baseHiddenTax,
                'incl_tax'   => $inclTax,
            );
        }

        $this->_addAmount(max(0, $tax));
        $this->_addBaseAmount(max(0, $baseTax));
        $address->setShippingTaxAmount(max(0, $tax));
        $address->setBaseShippingTaxAmount(max(0, $baseTax));
        $applied = $this->_calculator->getAppliedRates($taxRateRequest);
        // need to save the calc amounts
        $this->_saveAppliedTaxes($address, $applied, $tax, $baseTax, $rate, $calc, $baseCalc);

        return $this;
    }

    /* Overrides the logic of
     * @see Mage_Tax_Model_Sales_Total_Quote_Tax::_rowBaseCalculation()
     * by saving the calculated tax amount
     */
    protected function _rowBaseCalculation(Mage_Sales_Model_Quote_Address $address, $taxRateRequest)
    {
        $items = $this->_getAddressItems($address);
        foreach ($items as $item) {
            if ($item->getParentItem()) {
                continue;
            }
            if ($item->getHasChildren() && $item->isChildrenCalculated()) {
                foreach ($item->getChildren() as $child) {
                    $taxRateRequest->setProductClassId($child->getProduct()->getTaxClassId());
                    $rate = $this->_calculator->getRate($taxRateRequest);
                    $this->_calcRowTaxAmount($child, $rate);
                    $this->_addAmount($child->getTaxAmount());
                    $this->_addBaseAmount($child->getBaseTaxAmount());
                    $applied = $this->_calculator->getAppliedRates($taxRateRequest);
                    // need to save the calc amounts
                    $this->_saveAppliedTaxes(
                        $address,
                        $applied,
                        $child->getTaxAmount(),
                        $child->getBaseTaxAmount(),
                        $rate,
                        $child->getTaxCalcAmount(),
                        $child->getBaseTaxCalcAmount()
                    );
                }
                $this->_recalculateParent($item);
            }
            else {
                $taxRateRequest->setProductClassId($item->getProduct()->getTaxClassId());
                $rate = $this->_calculator->getRate($taxRateRequest);
                $this->_calcRowTaxAmount($item, $rate);
                $this->_addAmount($item->getTaxAmount());
                $this->_addBaseAmount($item->getBaseTaxAmount());
                $applied = $this->_calculator->getAppliedRates($taxRateRequest);
                // need to save the calc amounts
                $this->_saveAppliedTaxes(
                    $address,
                    $applied,
                    $item->getTaxAmount(),
                    $item->getBaseTaxAmount(),
                    $rate,
                    $item->getTaxCalcAmount(),
                    $item->getBaseTaxCalcAmount()
                );
            }
        }
        return $this;
    }

    /* Overrides the logic of
     * @see Mage_Tax_Model_Sales_Total_Quote_Tax::_calcRowTaxAmount()
     * by saving the calculated tax amount
     */
    protected function _calcRowTaxAmount($item, $rate)
    {
        $inclTax        = $item->getIsPriceInclTax();
        $subtotal       = $item->getTaxableAmount() + $item->getExtraRowTaxableAmount();
        $baseSubtotal   = $item->getBaseTaxableAmount() + $item->getBaseExtraRowTaxableAmount();
        $rateKey        = (string)$rate;
        $item->setTaxPercent($rate);

        $hiddenTax      = null;
        $baseHiddenTax  = null;
        switch ($this->_helper->getCalculationSequence($this->_store)) {
            case Mage_Tax_Model_Calculation::CALC_TAX_BEFORE_DISCOUNT_ON_EXCL:
            case Mage_Tax_Model_Calculation::CALC_TAX_BEFORE_DISCOUNT_ON_INCL:
                $calc     = $subtotal;
                $baseCalc = $baseSubtotal;
                break;
            case Mage_Tax_Model_Calculation::CALC_TAX_AFTER_DISCOUNT_ON_EXCL:
            case Mage_Tax_Model_Calculation::CALC_TAX_AFTER_DISCOUNT_ON_INCL:
                $discountAmount     = $item->getDiscountAmount();
                $baseDiscountAmount = $item->getBaseDiscountAmount();
                $calc     = max($subtotal - $discountAmount, 0);
                $baseCalc = max($baseSubtotal - $baseDiscountAmount, 0);
                if ($inclTax && $discountAmount > 0) {
                    $hiddenTax      = $this->_calculator->calcTaxAmount($discountAmount, $rate, $inclTax, false);
                    $baseHiddenTax  = $this->_calculator->calcTaxAmount($baseDiscountAmount, $rate, $inclTax, false);
                    $this->_hiddenTaxes[] = array(
                        'rate_key'   => $rateKey,
                        'qty'        => 1,
                        'item'       => $item,
                        'value'      => $hiddenTax,
                        'base_value' => $baseHiddenTax,
                        'incl_tax'   => $inclTax,
                    );
                } elseif ($discountAmount > $subtotal) { // case with 100% discount on price incl. tax
                    $hiddenTax      = $discountAmount - $subtotal;
                    $baseHiddenTax  = $baseDiscountAmount - $baseSubtotal;
                    $this->_hiddenTaxes[] = array(
                        'rate_key'   => $rateKey,
                        'qty'        => 1,
                        'item'       => $item,
                        'value'      => $hiddenTax,
                        'base_value' => $baseHiddenTax,
                        'incl_tax'   => $inclTax,
                    );
                }
                break;
        }

        $rowTax     = $this->_calculator->calcTaxAmount($calc, $rate, $inclTax);
        $baseRowTax = $this->_calculator->calcTaxAmount($baseCalc, $rate, $inclTax);

        // need to save the calc amounts
        $item->setTaxCalcAmount($calc);
        $item->setBaseTaxCalcAmount($baseCalc);

        $item->setTaxAmount(max(0, $rowTax));
        $item->setBaseTaxAmount(max(0, $baseRowTax));
        return $this;
    }

    /* Overrides the logic of
     * @see Mage_Tax_Model_Sales_Total_Quote_Tax::_totalBaseCalculation()
     * by saving the calculated tax amount
     */
    protected function _totalBaseCalculation(Mage_Sales_Model_Quote_Address $address, $taxRateRequest)
    {
        $items      = $this->_getAddressItems($address);
        $store      = $address->getQuote()->getStore();
        $taxGroups  = array();

        foreach ($items as $item) {
            if ($item->getParentItem()) {
                continue;
            }

            if ($item->getHasChildren() && $item->isChildrenCalculated()) {
                foreach ($item->getChildren() as $child) {
                    $taxRateRequest->setProductClassId($child->getProduct()->getTaxClassId());
                    $rate = $this->_calculator->getRate($taxRateRequest);
                    $taxGroups[(string)$rate]['applied_rates'] = $this->_calculator->getAppliedRates($taxRateRequest);
                    $this->_aggregateTaxPerRate($child, $rate, $taxGroups);
                    $inclTax = $child->getIsPriceInclTax();
                }
                $this->_recalculateParent($item);
            } else {
                $taxRateRequest->setProductClassId($item->getProduct()->getTaxClassId());
                $rate = $this->_calculator->getRate($taxRateRequest);
                $taxGroups[(string)$rate]['applied_rates'] = $this->_calculator->getAppliedRates($taxRateRequest);
                $this->_aggregateTaxPerRate($item, $rate, $taxGroups);
                $inclTax = $item->getIsPriceInclTax();
            }
        }

        foreach ($taxGroups as $rateKey => $data) {
            $rate = (float) $rateKey;

            // need to save the calc amounts
            $total = array_sum($data['totals']);
            $baseTotal = array_sum($data['base_totals']);

            $totalTax = $this->_calculator->calcTaxAmount($total, $rate, $inclTax);
            $baseTotalTax = $this->_calculator->calcTaxAmount($baseTotal, $rate, $inclTax);
            $this->_addAmount($totalTax);
            $this->_addBaseAmount($baseTotalTax);
            $this->_saveAppliedTaxes($address, $data['applied_rates'], $totalTax, $baseTotalTax, $rate, $total, $baseTotal);
        }
        return $this;
    }

    /* Overrides the logic of
     * @see Mage_Tax_Model_Sales_Total_Quote_Tax::_unitBaseCalculation()
     * by saving the calculated tax amount
     */
    protected function _unitBaseCalculation(Mage_Sales_Model_Quote_Address $address, $taxRateRequest)
    {
        $items = $this->_getAddressItems($address);
        foreach ($items as $item) {
            if ($item->getParentItem()) {
                continue;
            }

            if ($item->getHasChildren() && $item->isChildrenCalculated()) {
                foreach ($item->getChildren() as $child) {
                    $taxRateRequest->setProductClassId($child->getProduct()->getTaxClassId());
                    $rate = $this->_calculator->getRate($taxRateRequest);
                    $this->_calcUnitTaxAmount($child, $rate);
                    $this->_addAmount($child->getTaxAmount());
                    $this->_addBaseAmount($child->getBaseTaxAmount());
                    $applied = $this->_calculator->getAppliedRates($taxRateRequest);
                    // need to save the calc amounts
                    $this->_saveAppliedTaxes(
                        $address,
                        $applied,
                        $child->getTaxAmount(),
                        $child->getBaseTaxAmount(),
                        $rate, $child->getTaxCalcAmount(),
                        $child->getBaseTaxCalcAmount()
                    );
                }
                $this->_recalculateParent($item);
            }
            else {
                $taxRateRequest->setProductClassId($item->getProduct()->getTaxClassId());
                $rate = $this->_calculator->getRate($taxRateRequest);
                $this->_calcUnitTaxAmount($item, $rate);
                $this->_addAmount($item->getTaxAmount());
                $this->_addBaseAmount($item->getBaseTaxAmount());
                $applied = $this->_calculator->getAppliedRates($taxRateRequest);
                // need to save the calc amounts
                $this->_saveAppliedTaxes(
                    $address,
                    $applied,
                    $item->getTaxAmount(),
                    $item->getBaseTaxAmount(),
                    $rate,
                    $item->getTaxCalcAmount(),
                    $item->getBaseTaxCalcAmount()
                );
            }
        }
        return $this;
    }

    /* Overrides the logic of
     * @see Mage_Tax_Model_Sales_Total_Quote_Tax::_calcUnitTaxAmount()
     * by saving the calculated tax amount
     */
    protected function _calcUnitTaxAmount(Mage_Sales_Model_Quote_Item_Abstract $item, $rate)
    {
        $qty        = $item->getTotalQty();
        $inclTax    = $item->getIsPriceInclTax();
        $price      = $item->getTaxableAmount() + $item->getExtraTaxableAmount();
        $basePrice  = $item->getBaseTaxableAmount() + $item->getBaseExtraTaxableAmount();
        $rateKey    = (string)$rate;
        $item->setTaxPercent($rate);

        $hiddenTax      = null;
        $baseHiddenTax  = null;
        switch ($this->_config->getCalculationSequence($this->_store)) {
            case Mage_Tax_Model_Calculation::CALC_TAX_BEFORE_DISCOUNT_ON_EXCL:
            case Mage_Tax_Model_Calculation::CALC_TAX_BEFORE_DISCOUNT_ON_INCL:
                $calc     = $price;
                $baseCalc = $basePrice;
                break;
            case Mage_Tax_Model_Calculation::CALC_TAX_AFTER_DISCOUNT_ON_EXCL:
            case Mage_Tax_Model_Calculation::CALC_TAX_AFTER_DISCOUNT_ON_INCL:
                $discountAmount     = $item->getDiscountAmount() / $qty;
                $baseDiscountAmount = $item->getBaseDiscountAmount() / $qty;
                $calc     = max($price - $discountAmount, 0);
                $baseCalc = max($basePrice - $baseDiscountAmount, 0);
                if ($inclTax && $discountAmount > 0) {
                    $hiddenTax      = $this->_calculator->calcTaxAmount($discountAmount, $rate, $inclTax, false);
                    $baseHiddenTax  = $this->_calculator->calcTaxAmount($baseDiscountAmount, $rate, $inclTax, false);
                    $this->_hiddenTaxes[] = array(
                        'rate_key'   => $rateKey,
                        'qty'        => $qty,
                        'item'       => $item,
                        'value'      => $hiddenTax,
                        'base_value' => $baseHiddenTax,
                        'incl_tax'   => $inclTax,
                    );
                } elseif ($discountAmount > $price) { // case with 100% discount on price incl. tax
                    $hiddenTax      = $discountAmount - $price;
                    $baseHiddenTax  = $baseDiscountAmount - $basePrice;
                    $this->_hiddenTaxes[] = array(
                        'rate_key'   => $rateKey,
                        'qty'        => $qty,
                        'item'       => $item,
                        'value'      => $hiddenTax,
                        'base_value' => $baseHiddenTax,
                        'incl_tax'   => $inclTax,
                    );
                }
                break;
        }

        $unitTax     = $this->_calculator->calcTaxAmount($calc, $rate, $inclTax);
        $baseUnitTax = $this->_calculator->calcTaxAmount($baseCalc, $rate, $inclTax);

        // need to save the calc amounts
        $item->setTaxCalcAmount($qty*$calc);
        $item->setBaseTaxCalcAmount($qty*$baseCalc);

        $item->setTaxAmount($this->_store->roundPrice(max(0, $qty*$unitTax)));
        $item->setBaseTaxAmount($this->_store->roundPrice(max(0, $qty*$baseUnitTax)));

        return $this;
    }

    /**
     * Collect applied tax rates information on address level
     *
     * @param   Mage_Sales_Model_Quote_Address $address
     * @param   array $applied
     * @param   float $amount
     * @param   float $baseAmount
     * @param   float $rate
     * @param   float $saleAmount [OPTIONAL] The total used to calculate the amount
     * @param   float $baseSaleAmount [OPTIONAL] The base total used to calculate the amount
     */
    protected function _saveAppliedTaxes(Mage_Sales_Model_Quote_Address $address, $applied, $amount, $baseAmount, $rate, $saleAmount = 0, $baseSaleAmount = 0)
    {
        $previouslyAppliedTaxes = $address->getAppliedTaxes();
        $process = count($previouslyAppliedTaxes);

        foreach ($applied as $row) {
            /* even 0 rates need to be saved for reconciliation purposes
            if ($row['percent'] == 0) {
                continue;
            }
            */
            if (!isset($previouslyAppliedTaxes[$row['id']])) {
                $row['process']     = $process;
                $row['amount']      = 0;
                $row['base_amount'] = 0;
                $row['sale_amount'] = 0;
                $row['base_sale_amount'] = 0;
                $previouslyAppliedTaxes[$row['id']] = $row;
            }

            if (!is_null($row['percent'])) {
                $row['percent'] = $row['percent'] ? $row['percent'] : 1;
                $rate = $rate ? $rate : 1;

                $appliedAmount      = $amount/$rate*$row['percent'];
                $baseAppliedAmount  = $baseAmount/$rate*$row['percent'];
            } else {
                $appliedAmount      = 0;
                $baseAppliedAmount  = 0;
                foreach ($row['rates'] as $rate) {
                    $appliedAmount      += $rate['amount'];
                    $baseAppliedAmount  += $rate['base_amount'];
                }
            }

            $previouslyAppliedTaxes[$row['id']]['amount']       += $appliedAmount;
            $previouslyAppliedTaxes[$row['id']]['base_amount']  += $baseAppliedAmount;

            $previouslyAppliedTaxes[$row['id']]['sale_amount']       += $saleAmount;
            $previouslyAppliedTaxes[$row['id']]['base_sale_amount']  += $baseSaleAmount;

            // Remove taxes that are for on $0 for 0%
            if ($previouslyAppliedTaxes[$row['id']]['base_amount'] <= 0 && $previouslyAppliedTaxes[$row['id']]['base_sale_amount'] <= 0) {
                unset($previouslyAppliedTaxes[$row['id']]);
            }
        }
        $address->setAppliedTaxes($previouslyAppliedTaxes);
    }
}
