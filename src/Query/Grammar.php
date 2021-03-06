<?php

namespace LdapRecord\Query;

use UnexpectedValueException;

class Grammar
{
    /**
     * The query operators and their method names.
     *
     * @var array
     */
    public $operators = [
        '*'               => 'has',
        '!*'              => 'notHas',
        '='               => 'equals',
        '!'               => 'doesNotEqual',
        '!='              => 'doesNotEqual',
        '>='              => 'greaterThanOrEquals',
        '<='              => 'lessThanOrEquals',
        '~='              => 'approximatelyEquals',
        'starts_with'     => 'startsWith',
        'not_starts_with' => 'notStartsWith',
        'ends_with'       => 'endsWith',
        'not_ends_with'   => 'notEndsWith',
        'contains'        => 'contains',
        'not_contains'    => 'notContains',
    ];

    /**
     * The query wrapper.
     *
     * @var string|null
     */
    protected $wrapper;

    /**
     * Get all the available operators.
     *
     * @return array
     */
    public function getOperators()
    {
        return array_keys($this->operators);
    }

    /**
     * Wraps a query string in brackets.
     *
     * Produces: (query)
     *
     * @param string $query
     * @param string $prefix
     * @param string $suffix
     *
     * @return string
     */
    public function wrap($query, $prefix = '(', $suffix = ')')
    {
        return $prefix.$query.$suffix;
    }

    /**
     * Compiles the Builder instance into an LDAP query string.
     *
     * @param Builder $query
     *
     * @return string
     */
    public function compile(Builder $query)
    {
        if ($this->queryMustBeWrapped($query)) {
            $this->wrapper = 'and';
        }

        $filter = $this->generateAndConcatenate($query);

        if ($this->wrapper) {
            switch ($this->wrapper) {
                case 'and':
                    $filter = $this->compileAnd($filter);
                    break;
                case 'or':
                    $filter = $this->compileOr($filter);
                    break;
            }
        }

        return $filter;
    }

    /**
     * Determine if the query must be wrapped in an encapsulating statement.
     *
     * @param Builder $query
     *
     * @return bool
     */
    protected function queryMustBeWrapped(Builder $query)
    {
        return !$query->isNested() && $this->hasMultipleFilters($query);
    }

    /**
     * Determine if the query is using multiple filters.
     *
     * @param Builder $query
     *
     * @return bool
     */
    protected function hasMultipleFilters(Builder $query)
    {
        return $this->has($query, ['and', 'or', 'raw'], '>', 1);
    }

    /**
     * Determine if the query contains only the given filter statement types.
     *
     * @param Builder      $query
     * @param string|array $type
     * @param string       $operator
     * @param int          $count
     *
     * @return bool
     */
    protected function hasOnly(Builder $query, $type, $operator = '>=', $count = 1)
    {
        $types = (array) $type;

        $except = array_filter(array_keys($query->filters), function ($key) use ($types) {
            return !in_array($key, $types);
        });

        foreach ($except as $filterType) {
            if ($this->has($query, $filterType, '>', 0)) {
                return false;
            }
        }

        return $this->has($query, $types, $operator, $count);
    }

    /**
     * Determine if the query contains the given filter statement type.
     *
     * @param Builder      $query
     * @param string|array $type
     * @param string       $operator
     * @param int          $count
     *
     * @return bool
     */
    protected function has(Builder $query, $type, $operator = '>=', $count = 1)
    {
        $types = (array) $type;

        $filters = 0;

        foreach ($types as $type) {
            $filters += count($query->filters[$type]);
        }

        switch ($operator) {
            case '>':
                return $filters > $count;
            case '>=':
                return $filters >= $count;
            case '<':
                return $filters < $count;
            case '<=':
                return $filters <= $count;
            default:
                return $filters == $count;
        }
    }

    /**
     * Generate and concatenate the query filter.
     *
     * @param Builder $query
     *
     * @return string
     */
    protected function generateAndConcatenate(Builder $query)
    {
        $raw = $this->concatenate($query->filters['raw']);

        return
            $raw
            .$this->compileWheres($query)
            .$this->compileOrWheres($query);
    }

    /**
     * Assembles all where clauses in the current wheres property.
     *
     * @param Builder $builder
     *
     * @return string
     */
    protected function compileWheres(Builder $builder)
    {
        $filter = '';

        foreach ($builder->filters['and'] as $where) {
            $filter .= $this->compileWhere($where);
        }

        return $filter;
    }

    /**
     * Assembles all or where clauses in the current orWheres property.
     *
     * @param Builder $query
     *
     * @return string
     */
    protected function compileOrWheres(Builder $query)
    {
        $filter = '';

        foreach ($query->filters['or'] as $where) {
            $filter .= $this->compileWhere($where);
        }

        if ($this->hasMultipleFilters($query)) {
            // Here we will detect whether the entire query can be
            // wrapped inside of an "or" statement by checking
            // how many filter statements exist for each type.
            if (
                $this->has($query, 'or', '>=', 1) &&
                $this->has($query, 'and', '<=', 1) &&
                $this->has($query, 'raw', '=', 0)
            ) {
                $this->wrapper = 'or';
            } else {
                $filter = $this->compileOr($filter);
            }
        }

        return $filter;
    }

    /**
     * Concatenates filters into a single string.
     *
     * @param array $bindings
     *
     * @return string
     */
    public function concatenate(array $bindings = [])
    {
        // Filter out empty query segments.
        $bindings = array_filter($bindings, function ($value) {
            return (string) $value !== '';
        });

        return implode('', $bindings);
    }

    /**
     * Returns a query string for equals.
     *
     * Produces: (field=value)
     *
     * @param string $field
     * @param string $value
     *
     * @return string
     */
    public function compileEquals($field, $value)
    {
        return $this->wrap($field.'='.$value);
    }

    /**
     * Returns a query string for does not equal.
     *
     * Produces: (!(field=value))
     *
     * @param string $field
     * @param string $value
     *
     * @return string
     */
    public function compileDoesNotEqual($field, $value)
    {
        return $this->compileNot($this->compileEquals($field, $value));
    }

    /**
     * Alias for does not equal operator (!=) operator.
     *
     * Produces: (!(field=value))
     *
     * @param string $field
     * @param string $value
     *
     * @return string
     */
    public function compileDoesNotEqualAlias($field, $value)
    {
        return $this->compileDoesNotEqual($field, $value);
    }

    /**
     * Returns a query string for greater than or equals.
     *
     * Produces: (field>=value)
     *
     * @param string $field
     * @param string $value
     *
     * @return string
     */
    public function compileGreaterThanOrEquals($field, $value)
    {
        return $this->wrap("$field>=$value");
    }

    /**
     * Returns a query string for less than or equals.
     *
     * Produces: (field<=value)
     *
     * @param string $field
     * @param string $value
     *
     * @return string
     */
    public function compileLessThanOrEquals($field, $value)
    {
        return $this->wrap("$field<=$value");
    }

    /**
     * Returns a query string for approximately equals.
     *
     * Produces: (field~=value)
     *
     * @param string $field
     * @param string $value
     *
     * @return string
     */
    public function compileApproximatelyEquals($field, $value)
    {
        return $this->wrap("$field~=$value");
    }

    /**
     * Returns a query string for starts with.
     *
     * Produces: (field=value*)
     *
     * @param string $field
     * @param string $value
     *
     * @return string
     */
    public function compileStartsWith($field, $value)
    {
        return $this->wrap("$field=$value*");
    }

    /**
     * Returns a query string for does not start with.
     *
     * Produces: (!(field=*value))
     *
     * @param string $field
     * @param string $value
     *
     * @return string
     */
    public function compileNotStartsWith($field, $value)
    {
        return $this->compileNot($this->compileStartsWith($field, $value));
    }

    /**
     * Returns a query string for ends with.
     *
     * Produces: (field=*value)
     *
     * @param string $field
     * @param string $value
     *
     * @return string
     */
    public function compileEndsWith($field, $value)
    {
        return $this->wrap("$field=*$value");
    }

    /**
     * Returns a query string for does not end with.
     *
     * Produces: (!(field=value*))
     *
     * @param string $field
     * @param string $value
     *
     * @return string
     */
    public function compileNotEndsWith($field, $value)
    {
        return $this->compileNot($this->compileEndsWith($field, $value));
    }

    /**
     * Returns a query string for contains.
     *
     * Produces: (field=*value*)
     *
     * @param string $field
     * @param string $value
     *
     * @return string
     */
    public function compileContains($field, $value)
    {
        return $this->wrap("$field=*$value*");
    }

    /**
     * Returns a query string for does not contain.
     *
     * Produces: (!(field=*value*))
     *
     * @param string $field
     * @param string $value
     *
     * @return string
     */
    public function compileNotContains($field, $value)
    {
        return $this->compileNot($this->compileContains($field, $value));
    }

    /**
     * Returns a query string for a where has.
     *
     * Produces: (field=*)
     *
     * @param string $field
     *
     * @return string
     */
    public function compileHas($field)
    {
        return $this->wrap("$field=*");
    }

    /**
     * Returns a query string for a where does not have.
     *
     * Produces: (!(field=*))
     *
     * @param string $field
     *
     * @return string
     */
    public function compileNotHas($field)
    {
        return $this->compileNot($this->compileHas($field));
    }

    /**
     * Wraps the inserted query inside an AND operator.
     *
     * Produces: (&query)
     *
     * @param string $query
     *
     * @return string
     */
    public function compileAnd($query)
    {
        return $query ? $this->wrap($query, '(&') : '';
    }

    /**
     * Wraps the inserted query inside an OR operator.
     *
     * Produces: (|query)
     *
     * @param string $query
     *
     * @return string
     */
    public function compileOr($query)
    {
        return $query ? $this->wrap($query, '(|') : '';
    }

    /**
     * Wraps the inserted query inside an NOT operator.
     *
     * @param string $query
     *
     * @return string
     */
    public function compileNot($query)
    {
        return $query ? $this->wrap($query, '(!') : '';
    }

    /**
     * Assembles a single where query.
     *
     * @param array $where
     *
     * @throws UnexpectedValueException
     *
     * @return string
     */
    protected function compileWhere(array $where)
    {
        if (array_key_exists($where['operator'], $this->operators)) {
            $method = 'compile'.ucfirst($this->operators[$where['operator']]);

            return $this->{$method}($where['field'], $where['value']);
        }

        throw new UnexpectedValueException('Invalid LDAP filter operator ['.$where['operator'].']');
    }
}
