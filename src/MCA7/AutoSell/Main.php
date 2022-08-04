<?php

declare(strict_types=1);

namespace MCA7\AutoSell;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;
use cooldogedev\BedrockEconomy\api\BedrockEconomyAPI;


class Main extends PluginBase implements Listener
{

	private $db;
	private $prices;
	private $blocks = [];

	public function onEnable(): void
	{
		$this->db = new Config($this->getDataFolder() . "players.yml");
		$this->prices = new Config($this->getDataFolder() . "prices.yml");
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->blocks = $this->prices->getAll();
	}


	public function onLoad(): void
	{
		if ($this->getConfig()->get('ver') === false || $this->getConfig()->get('ver') !== 1.1) {
			$this->saveDefaultConfig();
			$this->getServer()->getLogger()->critical(
				TextFormat::RED . 'Invalid Config version - Update plugin or delete the old config file! Disabling plugin.'
			);
			$this->getServer()->getPluginManager()->disablePlugin($this);
		}
	}


	public function onDisable(): void
	{
		foreach ($this->blocks as $block) {
			unset($block);
		}
	}


	public function onJoin(PlayerJoinEvent $event): void
	{

		$player = $event->getPlayer()->getName();

		if (!$this->db->getNested($player)) {
			$this->db->setNested($player, "off");
			$this->db->save();
		}
	}


	public function onCommand(CommandSender $sender, Command $cmd, string $lable, array $args): bool
	{

		$prefix = $this->getConfig()->get("prefix");

		if ($cmd->getName() === 'autosell') {

			$player = $sender->getName();

			if (!($sender->hasPermission("autosell.command"))) {
				$sender->sendMessage($prefix . " " . TextFormat::RED . "You do not have the permission to use this command!");
				return true;
			}

			if (!($sender instanceof Player)) {
				$sender->sendMessage($prefix . " " . TextFormat::RED . "You can only use this command in-game!");
				return true;
			}

			if (!(isset($args[0]))) {
				$sender->sendMessage($prefix . " " . TextFormat::RED . "Usage: /autosell < on | off | add | remove | view >");
				return true;
			}

			switch (strtolower($args[0])) {
				case "on":

					$this->db->setNested($player, "on");
					$this->db->save();
					$sender->sendMessage($prefix . " " . TextFormat::GREEN . "Toggled AutoSell! (Enabled)");
					return true;

				case "off":

					$this->db->setNested($player, "off");
					$this->db->save();
					$sender->sendMessage($prefix . " " . TextFormat::RED . "Toggled AutoSell! (Disabled)");
					return true;

				case "add":

					if (!$sender->hasPermission('autosell.command.add')) {
						$sender->sendMessage($prefix . " " . TextFormat::RED . "You do not have the permission to use this sub-command!");
						return true;
					}
					if (count($args) < 2 || (!(is_numeric($args[1])))) {
						$sender->sendMessage($prefix . " " . TextFormat::RED . "Usage: /autosell add <price>");
						return true;
					}
					$check = $sender->getInventory()->getItemInHand();
					$block = $sender->getInventory()->getItemInHand()->getName();
					if ($check->isNull() || $check instanceof TieredTool) {
						$sender->sendMessage($prefix . " " . "Invalid block! Hold a block in hand and execute the command again.");
						return true;
					}
					$sellprice = $args[1];
					if ($this->prices->getNested($block)) {
						$this->prices->removeNested($block);
						$this->prices->setNested($block, $sellprice);
						$this->prices->save();
						$sender->sendMessage($prefix . " " . TextFormat::GREEN . "Updated sell price to" . TextFormat::WHITE . "$" . $sellprice);
						$this->blocks = $this->prices->getAll();
						return true;
					}
					$this->prices->setNested($block, $sellprice);
					$sender->sendMessage($prefix . " " . TextFormat::GREEN . "Added block/item successfully!");
					$this->prices->save();
					$this->blocks = $this->prices->getAll();
					return true;

				case "remove":

					if (!$sender->hasPermission('autosell.command.remove')) {
						$sender->sendMessage($prefix . " " . TextFormat::RED . "You do not have the permission to use this sub-command!");
						return true;
					}
					$item = $sender->getInventory()->getItemInHand()->getName();
					if ($this->prices->getNested($item)) {
						$this->prices->removeNested($item);
						$sender->sendMessage($prefix . " " . TextFormat::GREEN . "Removed item/block successfully!");
						$this->prices->save();
						$this->blocks = $this->prices->getAll();
					} else {
						$sender->sendMessage($prefix . " " . TextFormat::RED . $item . " has not been added before.");
					}
					return true;

				case "view":
					$sender->sendMessage(TextFormat::YELLOW . "-- VIEWING PRICES FOR AUTOSELL --");
					foreach ($this->blocks as $key => $value) {
						$sender->sendMessage(TextFormat::BLUE . $key . TextFormat::WHITE . " - $" . $value);
					}
			}

		}

		return true;

	}


	/**
	 * @priority MONITOR
	 */

	public function onBreak(BlockBreakEvent $event): void
	{
		$player = $event->getPlayer();
		$name = $event->getPlayer()->getName();
		if (!($player->hasPermission("autosell.command"))) return;
		if ($this->db->getNested($name) == "off") return;
		if ($event->isCancelled()) {
			$player->sendTip(TextFormat::RED . "You cannot AutoSell protected blocks!");
			return;
		}
		if ($player->isCreative()) {
			$player->sendTip(TextFormat::RED . "You cannot AutoSell in Creative Mode!");
			return;
		}
		if (!in_array($player->getWorld()->getFolderName(), $this->getConfig()->get("worlds"))) {
			$player->sendTip(TextFormat::RED . "You cannot AutoSell in this world!");
			return;
		}
		$count = 0;
		foreach ($event->getDrops() as $drop) {
			if (isset($this->blocks[$drop->getName()])) {
				$count += $drop->getCount();
			}
		}
		if (isset($this->blocks[$drop->getName()])) {
			$event->setDrops([]);
			$itemname = $drop->getName();
			$price = (float)$this->blocks[$itemname] * $count;
			$player->sendTip(
				TextFormat::GREEN . "Sold" . TextFormat::AQUA . " " . $itemname . "x" . $count . TextFormat::GREEN . " for" . TextFormat::YELLOW . " $" . $price
			);
			BedrockEconomyAPI::legacy()->addToPlayerBalance($name, $price);
		}

	}

}