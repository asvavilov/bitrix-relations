<?
/*
* реализация связи "один-ко-многим"
* https://github.com/asvavilov/bitrix-relations/
* TODO:
* - отвязать код от групп и продуктов (GROUP, PRODUCTS), то есть вынести коды инфоблоков и свойств в настройки (переменные или константы)
* - сделать универсальней, чтобы другие отношения поддерживались
*/

// регистрируем обработчики событий
AddEventHandler("iblock", "OnAfterIBlockElementAdd", array("MyRelations", "OnAfterIBlockElementAddHandler"));
AddEventHandler("iblock", "OnAfterIBlockElementUpdate", array("MyRelations", "OnAfterIBlockElementUpdateHandler"));
AddEventHandler("iblock", "OnAfterIBlockElementDelete", array("MyRelations", "OnAfterIBlockElementDeleteHandler"));

CModule::IncludeModule("iblock");

/**
* bitrix-relations
*/
class MyRelations
{

	/**
	* Возвращает инфоблок по идентикатору
	*/
	function getRelatedIBlock($iblock_id)
	{
		$res = CIBlock::GetByID($iblock_id);
		return $res->GetNext();
	}

	/**
	* Сохраняет группу
	*/
	function save_group($arFields, $iblock)
	{
		$res = CIBlockProperty::GetList(array(), array("IBLOCK_ID"=>$iblock["ID"], "CODE"=>"PRODUCTS"));
		$prop_products = $res->GetNext();
		if (!$prop_products) return ;
		$res = $arFields["PROPERTY_VALUES"][$prop_products["ID"]];
		$product_ids = array();
		if ($res)
		{
			foreach ($res as $row)
			{
				if (!$row["VALUE"]) continue;
				$product_ids[$row["VALUE"]] = $row["VALUE"];
			}
		}
		self::resetProductsGroup($arFields["ID"], $product_ids);
		if ($product_ids)
		{
			foreach ($product_ids as $product_id)
			{
				if (!$product_id) continue;
				$arFilter = array("IBLOCK_CODE"=>"product", "ID"=>$product_id, "PROPERTY_GROUP"=>$arFields["ID"]);
				$res = CIBlockElement::GetList(array(), $arFilter);
				$already_child_product = $res->GetNext();
				if ($already_child_product) continue;
				CIBlockElement::SetPropertyValuesEx($product_id, false, array("GROUP" => $arFields["ID"]));
			}
		}
	}

	/**
	* Сбрасывает продукты для группы
	*/
	function resetProductsGroup($group_id, $product_ids = array())
	{
		if (!$group_id) return ;
		$arFilter = array("IBLOCK_CODE"=>"product", "PROPERTY_GROUP"=>$group_id);
		$res = CIBlockElement::GetList(array(), $arFilter, false, false, array());
		while($prev_product = $res->GetNext())
		{
			if ($product_ids[$prev_product["ID"]]) continue;
			CIBlockElement::SetPropertyValuesEx($prev_product["ID"], false, array("GROUP" => null));
		}
	}

	/**
	* Сохраняет продукт
	*/
	function save_product($arFields, $iblock)
	{
		$res = CIBlockProperty::GetList(array(), array("IBLOCK_ID"=>$iblock["ID"], "CODE"=>"GROUP"));
		$prop_group = $res->GetNext();
		if (!$prop_group) return ;
		$res = $arFields["PROPERTY_VALUES"][$prop_group["ID"]];
		$group_ids = array();
		if ($res)
		{
			if (!is_array($res)) $res = array(array("VALUE" => $res));
			foreach ($res as $row)
			{
				if (!is_array($row)) $row = array("VALUE" => $row);
				if (!$row["VALUE"]) continue;
				$group_ids[$row["VALUE"]] = $row["VALUE"];
			}
		}
		self::resetGroupProduct($arFields["ID"], $group_ids);
		if ($group_ids)
		{
			foreach ($group_ids as $group_id)
			{
				if (!$group_id) continue;
				$res = CIBlockElement::GetByID($group_id);
				$group = $res->GetNextElement();
				if (!$group) continue;
				$prop_products = $group->GetProperty("PRODUCTS");
				$product_ids = $prop_products["VALUE"];
				if (in_array($arFields["ID"], $product_ids)) continue;
				$product_ids[] = $arFields["ID"];
				$prop_products = array();
				foreach ($product_ids as $product_id)
				{
					$prop_products[] = array("VALUE"=>$product_id);
				}
				CIBlockElement::SetPropertyValuesEx($group_id, false, array("PRODUCTS" => $prop_products));
			}
		}
	}

	/**
	* Сбрасывает группу для продукта
	*/
	function resetGroupProduct($product_id, $group_ids = array())
	{
		if (!$product_id) return ;
		$arFilter = array("IBLOCK_CODE"=>"group", "PROPERTY_PRODUCTS"=>$product_id);
		$res = CIBlockElement::GetList(array(), $arFilter, false, false, array());
		while($prev_group = $res->GetNextElement())
		{
			$fields = $prev_group->GetFields();
			if ($group_ids[$fields["ID"]]) continue;
			$prop_products = $prev_group->GetProperty("PRODUCTS");
			$product_ids = $prop_products["VALUE"];
			if (!in_array($product_id, $product_ids)) continue;
			$prop_products = array();
			foreach ($product_ids as $p_id)
			{
				if ($p_id == $product_id) continue;
				$prop_products[] = array("VALUE"=>$p_id);
			}
			CIBlockElement::SetPropertyValuesEx($fields["ID"], false, array("PRODUCTS" => $prop_products));
		}
	}

	/**
	* Обработчик события OnAfterIBlockElementAdd
	*/
	function OnAfterIBlockElementAddHandler(&$arFields)
	{
		if (!$arFields["RESULT"]) return ;
		$iblock = self::getRelatedIBlock($arFields["IBLOCK_ID"]);
		if (!$iblock) return ;
		switch ($iblock["CODE"])
		{
			case "group":
				self::save_group($arFields, $iblock);
				break;
			case "product":
				self::save_product($arFields, $iblock);
				break;
		}
	}

	/**
	* Обработчик события OnAfterIBlockElementUpdate
	*/
	function OnAfterIBlockElementUpdateHandler(&$arFields)
	{
		if (!$arFields["RESULT"]) return ;
		$iblock = self::getRelatedIBlock($arFields["IBLOCK_ID"]);
		if (!$iblock) return ;
		switch ($iblock["CODE"])
		{
			case "group":
				self::save_group($arFields, $iblock);
				break;
			case "product":
				self::save_product($arFields, $iblock);
				break;
		}
	}

	/**
	* Обработчик события OnAfterIBlockElementDelete
	*/
	function OnAfterIBlockElementDeleteHandler($element)
	{
		$iblock = self::getRelatedIBlock($element["IBLOCK_ID"]);
		if (!$iblock) return ;
		switch ($iblock["CODE"])
		{
			case "group":
				self::resetProductsGroup($element["ID"]);
				break;
			case "product":
				self::resetGroupProduct($element["ID"]);
				break;
		}
	}

}
?>
