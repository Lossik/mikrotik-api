<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Protocol;

use Lossik\DeviceCommunication\Query;
use Lossik\DeviceCommunication\QueryOperator;

/**
 * Compiles a Query tree to the query words of the RouterOS API.
 *
 * The API evaluates query words on a stack of booleans: `?k=v`, `?k`, `?-k`, `?<k=v` and `?>k=v` push a value,
 * `?#&` and `?#|` pop two values and push their AND / OR, `?#!` negates the top value. An operator over n operands
 * therefore follows the words of its operands and is repeated n - 1 times. Source: RouterOS API manual, section
 * "Queries" (https://manual.mikrotik.com/docs/developer-guides/api/, version 7.26): "& pops two values and pushes
 * the result of logical and operation", "| pops two values and pushes the result of logical or operation", examples
 * `?#|` and `?#&!`.
 */
final class QueryCompiler
{
    /**
     * @return list<string>
     */
    public static function compile(Query $query): array
    {
        $key = $query->key ?? '';
        $value = $query->value ?? '';

        return match ($query->operator) {
            QueryOperator::Where => ['?' . $key . '=' . $value],
            QueryOperator::Has => ['?' . $key],
            QueryOperator::HasNot => ['?-' . $key],
            QueryOperator::Less => ['?<' . $key . '=' . $value],
            QueryOperator::Greater => ['?>' . $key . '=' . $value],
            QueryOperator::And => self::combine($query->operands, '?#&'),
            QueryOperator::Or => self::combine($query->operands, '?#|'),
            QueryOperator::Not => [...self::operandWords($query->operands), '?#!'],
        };
    }

    /**
     * @param list<Query> $operands
     * @return list<string>
     */
    private static function combine(array $operands, string $operator): array
    {
        $words = self::operandWords($operands);
        for ($i = 1; $i < count($operands); $i++) {
            $words[] = $operator;
        }

        return $words;
    }

    /**
     * @param list<Query> $operands
     * @return list<string>
     */
    private static function operandWords(array $operands): array
    {
        $words = [];
        foreach ($operands as $operand) {
            array_push($words, ...self::compile($operand));
        }

        return $words;
    }
}
