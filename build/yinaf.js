


var state = function() {
  this.state = {};
};
rocket.inherits(state, rocket.EventTarget);



var cache = function() {
  state.apply(this, arguments);
  this.cache = {};
};
rocket.inherits(cache, state);


cache.last_insert_id = 0;


cache.cache = {};


cache.prototype.load = function(data) {
  cache.cache = data;
};


cache.prototype.create = function(table, attributes) {
  if (!this.cache[table]) {
    this.cache.table = {};
  }
  return this.update(table, rocket.extend(
    rocket.object(table + '_id', --cache.last_insert_id), 
    attributes
  ));
};


cache.prototype.read = function(table, ids_or_attributes) {
  var attributes = rocket.isArray(ids_or_attributes) ?
    rocket.object(table + '_id', ids_or_attributes) :
    ids_or_attributes;
  for (var column in attributes) {
    if (!(rocket.isArray(attributes[column]))) {
      attributes[column] = [attributes[column]];
    }
  }
  var caches = [cache.cache];
  var layers = this.get_layers();
  for (var i = 0; layers[i]; ++i) {
    cache.caches.push(layers[i].cache);
  }
  if (rocket.equal([table + '_id'], rocket.keys(attributes))) {
    return this.read_results_(caches, table, attributes[table + '_id']);
  } else {
    var matches = {};
    var mismatches = {};
    for (var i = caches.length - 1; i > -1; --i) {
      this.read_helper_(matches, mismatches, caches[i], table, attributes);
    }
    var ids = [];
    for (var id in matches) {
      if (rocket.isEmpty(matches[id])) {
        ids.push(id);
      }
    }
    return this.read_results_(caches, table, ids);
  }
};


cache.prototype.read_helper_ = function(matches, mismatches, cache, table, attributes) {
  if (table in cache) {
    for (var id in cache[table]) {
      if (!(id in mismatches)) {
        if (!(id in matches)) {
          matches[id] = rocket.clone(attributes);
        }
        for (var column in matches[id]) {
          if (column in cache[table][id]) {
            var match = false;
            for (var i = 0; matches[id][column][i]; ++i) {
              if (matches[id][column][i] == cache[table][id][column]) {
                match = true;
              }
            }
            if (match) {
              delete matches[id][column];
            } else {
              delete matches[id];
              mismatches[id] = true;
            }
          }
        }
      }
    }
  }
};


cache.prototype.read_results_ = function(caches, table, ids) {
  var results = {};
  for (var i = 0; ids[i]; ++i) {
    results[ids[i]] = {};
    for (var j = 0; caches[j]; ++j) {
      if (
        (table in caches[j]) &&
        (ids[i] in caches[j][table])
      ) {
        rocket.extend(
          results[ids[i]], 
          caches[j][table][ids[i]]
        );
      }
    }
  }
  return results;
};


cache.prototype.update = function(table, attributes) {
  var id = attributes[table + '_id'];
  switch (undefined) {
    case this.cache[table]:
      this.cache[table] = {};
    case this.cache[table][id]:
      this.cache[table][id] = {};
  }
  rocket.extend(this.cache[table][id], attributes);
  return this.get(table, id);
};


cache.prototype.del = function(table, id) {
  return this.update(table, {'deleted': '1'});
};


cache.prototype.get = function(table, id_or_attributes) {
  if (
    (typeof id_or_attributes === 'number') ||
    (typeof id_or_attributes === 'string')
  ) {
    return this.read(table, [id_or_attributes]);
  } else {
    return this.read(table, id_or_attributes);
  }
};


cache.prototype.flush = function(callback) {
  this.flush_remove_unnecessary_updates();
  var negative_pointers = this.flush_remove_negative_pointers();
  var unresolved_negative_pointers = [];
  this.flush_move_unresolved_negative_pointer_rows_back(negative_pointers, unresolved_negative_pointers);
  var aliases = [];
  var calls = [];
  this.flush_get_inserts(calls, aliases, negative_pointers);
  this.flush_get_updates(calls, aliases);
  this.flush_get_updates_from_negative_pointers(callas, aliases, negative_pointers);
  if (calls.length) {
    var self = this;
    api('api', 'multiple', calls, function(result) {
      self.flush_handle_result(result, aliases, negative_pointers, unresolved_negative_pointers);
      callback();
    });
  } else {
    callback();
  }
};


cache.prototype.flush_remove_unnecessary_updates = function() {
  for (var table in this.cache) {
    if (table in cache.cache) {
      for (var id in this.cache[table]) {
        if (id in cache.cache[table]) {
          for (var column in this.cache[table][id]) {
            if (cache.cache[table][id][column] == this.cache[table][id][column]) {
              delete this.cache[table][id][column];
            }
          }
          if (rocket.isEmpty(this.cache[table][id])) {
            delete this.cache[table][id];
          }
        }
      }
      if (rocket.isEmpty(this.cache[table])) {
        delete this.cache[table];
      }
    }
  }
};


cache.prototype.flush_remove_negative_pointers = function() {
  var pointers = [];
  for (var table in this.cache) {
    for (var id in this.cache[table]) {
      for (var column in this.cache[table][id]) {
        if (
          (column.substr(column.length - 3) === '_id') &&
          (this.cache[table][id][column] < 0)
        ) {
          this.pointers.push({
            'table': table,
            'id': id,
            'column': column,
            'value': this.cache[table][id][column],
            'pointer': this.cache[table][id],
            'self': (table === column.substr(0, column.length - 3))
          });
          delete this.cache[table][id][column];
        }
      }
    }
  }
  return pointers;
};


cache.prototype.flush_move_unresolved_negative_pointer_rows_back = function(negative_pointers, unresolved_negative_pointers) {
  for (var i = 0; negative_pointers[i]; ++i) {
    var pointer = negative_pointers[i];
    if (!pointer.self) {
      var match = false;
      for (var j = 0; negative_pointers[j]; ++j) {
        if (
          (negative_pointers[j].self) &&
          (pointer.value === negative_pointers[j].value)
        ) {
          match = true;
          break;
        }
      }
      if (!match) {
        this.flush_replace_negative_pointer(negative_pointers, pointer.table, pointer.id, unresolved_negative_pointers);
        this.get_previous_layer().cache.update(pointer.table, this.cache[pointer.table][pointer.id]);
        delete this.cache[pointer.table][pointer.id];
        return this.flush_move_unresolved_negative_pointer_rows_back(negative_pointers, unresolved_negative_pointers);
      }
    }
  }
};


cache.prototype.flush_replace_negative_pointer = function(negative_pointers, table, id, unresolved_negative_pointers) {
  for (var i = 0; negative_pointers[i]; ++i) {
    if (
      (negative_pointers[i].table === table) &&
      (negative_pointers[i].id === id)
    ) {
      this.cache[table][id][negative_pointers[i].column] = negative_pointers[i].value;
    }
    unresolved_negative_pointers.push(negative_pointers[i]);
    negative_pointers.splice(i--, 1);
  }
};


cache.prototype.flush_get_inserts = function(calls, aliases, negative_pointers) {
  for (var table in this.cache) {
    for (var id in this.cache[table]) {
      if (id < 0) {
        for (var i = 0; negative_pointers[i]; ++i) {
          if (negative_pointers[i].pointer === this.cache[table][id]) {
            calls.push({
              'class': table,
              'function': 'create',
              'arguments': this.cache[table][id],
              'alias': (negative_pointers.alias = aliases.length)
            });
            aliases.push(table);
            break;
          }
        }
      }
    }
  }
};


cache.prototype.flush_get_updates = function(aliases) {
  for (var table in this.cache) {
    for (var id in this.cache[table]) {
      if (id > 0) {
        calls.push({
          'alias': aliases.length
          'class': table,
          'function': 'create',
          'arguments': this.cache[table]
        });
        aliases.push(table);
      }
    }
  }
};


cache.prototype.flush_get_updates_from_negative_pointers = function(calls, aliases, negative_pointers) {
  for (var i = 0; negative_pointers[i]; ++i) {
    var pointer = negative_pointers[i];
    if (!pointer.self) {
      var id;
      if (pointer.id > 0) {
        id = pointer.id;
      } else {
        for (j = 0; negative_pointers[j]; ++j) {
          if (
            (negative_pointers[j].self) &&
            (negative_pointers[j].value === pointer.id)
          ) {
            id = '=' + negative_pointers[j].alias;
          }
        }
      }
      for (var j = 0; negative_pointers[j]; ++j) {
        if (
          (negative_pointers[j].self) &&
          (negative_pointers[j].value === negative_pointers[i].value)
        ) {
          var value = '=' + negative_pointers[j].alias;
        }
      }
      calls.push({
        'alias': aliases.length,
        'class': pointer.table,
        'function': 'update',
        'arguments': rocket.extend(
          rocket.object(pointer.table + '_id', id),
          rocket.object(pointer.column, value)
        )
      });
      aliases.push(pointer.table);
    }
  }
};


cache.prototype.flush_handle_result = function(result, aliases, negative_pointers, unresolved_negative_pointers) {
  for (var alias in result) {
    if (!(aliases[alias] in cache.cache)) {
      cache.cache = {};
    }
    rocket.extend(cache.cache, rocket.object(
      result[alias][aliases[alias] + '_id'],
      result[alias]
    ));
  }
};




var layer = function() {
  cache.apply(this, arguments);
  this.get_class_names_()  
  if (this instanceof layer) {
    layer.layers.push(this);
    if (this.get_previous_layer()) {
      this.state = rocket.clone(this.get_previous_layer().state);
    }
  }
};
rocket.inherits(layer, cache);


layer.layers = [];


layer.prototype.get_previous_layer = function() {
  for (var i = 0; layer.layers[i]; ++i) {
    if (layer.layers[i] === this) {
      return layer.layers[i - 1];
    }
  }
};


layer.prototype.get_layers = function() {
  return layer.layers;
};


layer.prototype.get_class_names_ = function() {
  return this.constructor.prototype.class_names = this.class_names = this.class_names || 
    this.get_class_name_recursive_(window);
};


layer.prototype.get_class_name_recursive_ = function(parent, opt_prefix) {
  var name;
  for (var i in parent) {
    if (typeof parent[i] === 'function') {
      var name = opt_prefix ? rocket.clone(opt_prefix) : [];
      name.push(i);
      if (parent[i] === this.constructor) {
        return name;
      } else {
        if (
          (parent !== parent[i]) &&
          (name = this.get_class_name_recursive_(parent[i], name))
        ) {
          return name;
        }
      }
    }
  }
};


layer.prototype.layer_previous_container_;


layer.prototype.render = function(opt_parent) {
  var container = rocket.createElement('div');
  rocket.EventTarget.removeAllEventListeners();
  this.decorate(container);
  if (container.innerHTML()) {
    var containers = [];
    for (var i = 0; this.class_names[i]; ++i) {
      containers.push(rocket.createElement('div').addClass(this.class_names[i]));
      if (i) {
        containers[i - 1].appendChild(containers[i]);
      }
    }
    containers[containers.length - 1].appendChild(container);
    if (
      (this.layer_previous_container_) &&
      (this.layer_previous_container_.parentNode().length)
    ) {
      this.layer_previous_container_.parentNode().replaceChild(containers[0], this.layer_previous_container_);
    } else {
      (opt_parent || $('body').innerHTML('')).appendChild(containers[0]);
    }
    this.layer_previous_container_ = containers[0];
    this.dispatchEvent('rendered');
  }
};


layer.prototype.decorate = function() {};


layer.prototype.render_previous = function(opt_cancel) {
  if (opt_cancel) {
    layer.layers.pop();
    layer.layers[layer.layers.length - 1].render();
  } else {
    layer.layers[layer.layers.length - 2].state = this.state;
    rocket.EventTarget.removeAllEventListeners();
    this.flush(function() {
      layer.layers.pop();
      layer.layers[layer.layers.length - 1].render();
    });
  }
};

layer.prototype.render_clear = function() {
  this.render();
  layer.layers = [this];
};



var component = function() {
  layer.apply(this, arguments);
};
rocket.inherits(component, layer);


component.prototype.decorate = function() {};



var api = function(cls, fnct, opt_args, opt_success, opt_xhr) {

  var xhr = opt_xhr || (new rocket.XMLHttpRequest());

  xhr.data = {
    'class': cls,
    'function': fnct,
    'arguments': rocket.JSON.stringify((opt_args === undefined) ? null : opt_args)
  };

  xhr.open('POST', 'moxee_api');
  
  xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');

  xhr.addEventListener('success', function() {
    try {
      var response = rocket.JSON.parse(this.responseText);
      if (response.success) {
        if (opt_success) {
          opt_success(response.result);
        }
      } else {
        throw response.error;
      }
    } catch (e) {
      if (error) {
        error(e);
      }
    }
  });
  
  xhr.addEventListener('error', function() {
    if (error) {
      error('XMLHttpRequest failure');
    }
  });

  xhr.send();

};



var error = function(str) {
  alert('error:"' + str + '"');
};
