<?php

// namespace DTApi\Repository;
// use Validator;
// use Illuminate\Database\Eloquent\Model;
// use DTApi\Exceptions\ValidationException;
// use Illuminate\Database\Eloquent\ModelNotFoundException;

// Improve:
// -------------------------------------------
namespace DTApi\Http\Repositories;
use Illuminate\Database\Eloquent\Model;
use DTApi\Exceptions\ValidationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Validator;


class BaseRepository
{

    /**
     * @var Model
     */
    // protected $model;
    
    // Improve:
    // -------------------------------------------
    protected Model $model;

    /**
     * @var array
     */
    // protected $validationRules = [];
    
    // Improve:
    // -------------------------------------------
    protected array $validationRules = [];
    
    /**
     * @param Model $model
     */
    // public function __construct(Model $model = null)
    
    // Improve:
    // -------------------------------------------
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * @return array
     */
    public function validatorAttributeNames()
    {
        return [];
    }

    /**
     * @return Model
     */
    // public function getModel()
    // Improve:
    // -------------------------------------------
    public function getModel() : Model
    {
        return $this->model;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection|Model[]
     */
    // public function all()
    // Improve:
    // -------------------------------------------
    public function all(): Collection
    {
        return $this->model->all();
    }
    
    /**
     * @param integer $id
     * @return Model|null
     */
    // public function find($id)
    // Improve:
    // -------------------------------------------
    public function find(int $id): ?Model
    {
        return $this->model->find($id);
    }

    public function with($array)
    {
        return $this->model->with($array);
    }

    /**
     * @param integer $id
     * @return Model
     * @throws ModelNotFoundException
     */
    public function findOrFail($id)
    {
        return $this->model->findOrFail($id);
    }

    /**
     * @param string $slug
     * @return Model
     * @throws ModelNotFoundException
     */
    public function findBySlug($slug)
    {

        return $this->model->where('slug', $slug)->first();

    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query()
    {
        return $this->model->query();
    }

    /**
     * @param array $attributes
     * @return Model
     */
    public function instance(array $attributes = [])
    {
        $model = $this->model;
        return new $model($attributes);
    }

    /**
     * @param int|null $perPage
     * @return mixed
     */
    // public function paginate($perPage = null)
    // Improve:
    // -------------------------------------------
    public function paginate(?int $perPage = null): LengthAwarePaginator
    {
        return $this->model->paginate($perPage);
    }

    public function where($key, $where)
    {
        return $this->model->where($key, $where);
    }

    /**
     * @param array $data
     * @param null $rules
     * @param array $messages
     * @param array $customAttributes
     * @return \Illuminate\Validation\Validator
     */
    // public function validator(array $data = [], $rules = null, array $messages = [], array $customAttributes = [])
    // {
    //     if (is_null($rules)) {
    //         $rules = $this->validationRules;
    //     }

    //     return Validator::make($data, $rules, $messages, $customAttributes);
    // }

    // Improve:
    // -------------------------------------------
    public function validator(array $data, ?array $rules = null, array $messages = [], array $customAttributes = []): Validator
    {
        $rules = $rules ?? $this->validationRules;

        $validator = Validator::make($data, $rules, $messages, $customAttributes);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator;
    }

    /**
     * @param array $data
     * @param null $rules
     * @param array $messages
     * @param array $customAttributes
     * @return bool
     * @throws ValidationException
     */
    public function validate(array $data = [], $rules = null, array $messages = [], array $customAttributes = [])
    {
        $validator = $this->validator($data, $rules, $messages, $customAttributes);
        return $this->_validate($validator);
    }

    // Improve:
    // -------------------------------------------
    // protected function validate(Validator $validator): void
    // {
    //     if (!empty($attributeNames = $this->validatorAttributeNames())) {
    //         $validator->setAttributeNames($attributeNames);
    //     }
    
    //     if ($validator->fails()) {
    //         throw new ValidationException($validator);
    //     }
    // }
    public function validate(array $data, ?array $rules = null, array $messages = [], array $customAttributes = []): void 
    {
        $validator = $this->validator($data, $rules, $messages, $customAttributes);
        
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
    
    /**
     * @param array $data
     * @return Model
     */
    // public function create(array $data = [])
    // {
    //     return $this->model->create($data);
    // }
    // Improve:
    // -------------------------------------------
    public function create(array $data): Model
    {
        $validatedData = validator($data, $this->validationRules)->validate();
        
        return $this->model->create($validatedData);
    }
                
    /**
     * @param integer $id
     * @param array $data
     * @return Model
     */
    // public function update($id, array $data = [])
    // {
    //     $instance = $this->findOrFail($id);
    //     $instance->update($data);
    //     return $instance;
    // }
    // Improve:
    // -------------------------------------------
    public function update(int $id, array $data): Model
    {
        validator($data, $this->validationRules)->validate();

        $instance = $this->model->findOrFail($id);
        
        if (!$instance->update($data)) {
            throw new \RuntimeException('Update failed');
        }

        return $instance;
    }

    /**
     * @param integer $id
     * @return Model
     * @throws \Exception
     */
    // public function delete($id)
    // {
    //     $model = $this->findOrFail($id);
    //     $model->delete();
    //     return $model;
    // }
    // Improve:
    // -------------------------------------------
    public function delete(int $id): bool
    {
        $model = $this->model->findOrFail($id);
        
        // Optionally, we may start a transaction here if this action should be part of a higher-level transaction.
        $deleted = $model->delete();
        
        // Optionally, if more complex logic is involved and events need to be handled, consider using Eloquent events.
        
        // Return the success status of the deletion operation.
        return $deleted;
    }

    /**
     * @param \Illuminate\Validation\Validator $validator
     * @return bool
     * @throws ValidationException
     */
    // protected function _validate(\Illuminate\Validation\Validator $validator)
    // {
    //     if (!empty($attributeNames = $this->validatorAttributeNames())) {
    //         $validator->setAttributeNames($attributeNames);
    //     }

    //     if ($validator->fails()) {
    //         return false;
    //         throw (new ValidationException)->setValidator($validator);
    //     }

    //     return true;
    // }
    // Improve:
    // -------------------------------------------
    protected function validate(\Illuminate\Validation\Validator $validator): void
    {
        if (!empty($attributeNames = $this->validatorAttributeNames())) {
            $validator->setAttributeNames($attributeNames);
        }

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

}
