


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
    caches.push(layers[i].cache);
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
  for (var id in results) {
    if (+results[id].deleted) {
      delete results[id];
    }
  }
  return results;
};


cache.prototype.update = function(table, attributes) {
  var id = attributes[table + '_id'];
  if (!(table in this.cache)) {
    this.cache[table] = {};
  }
  if (!(id in this.cache[table])) {
    this.cache[table][id] = {};
  }
  rocket.extend(this.cache[table][id], attributes);
  return this.get(table, id);
};


cache.prototype.del = function(table, id) {
  return this.update(table, rocket.extend(rocket.object(
    table + '_id',
    id
  ), {'deleted': '1'}));
};


cache.prototype.get = function(table, id_or_attributes) {
  var results;
  if (
    (typeof id_or_attributes === 'number') ||
    (typeof id_or_attributes === 'string')
  ) {
    results = this.read(table, [id_or_attributes]);
  } else {
    results = this.read(table, id_or_attributes);
  }
  for (var id in results) {
    return results[id];
  }
};


cache.prototype.flush = function(callback) {
  this.flush_remove_unnecessary_updates();
  var negative_pointers = this.flush_remove_negative_pointers();
  var unresolved_negative_pointers = [];
  this.flush_move_unresolved_negative_pointer_rows_back(negative_pointers, unresolved_negative_pointers);
  var alias_to_table = [];
  var calls = [];
  this.flush_get_inserts(calls, alias_to_table, negative_pointers);
  this.flush_get_updates(calls, alias_to_table);
  this.flush_get_updates_from_negative_pointers(calls, alias_to_table, negative_pointers);
  this.cache = {};
  if (calls.length) {
    var self = this;
    api('api', 'multiple', calls, function(result) {
      self.flush_handle_result(result, alias_to_table, negative_pointers, unresolved_negative_pointers);
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
          pointers.push({
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
        this.get_previous_layer().update(pointer.table, this.cache[pointer.table][pointer.id]);
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


cache.prototype.flush_get_inserts = function(calls, alias_to_table, negative_pointers) {
  for (var table in this.cache) {
    for (var id in this.cache[table]) {
      if (id < 0) {
        for (var i = 0; negative_pointers[i]; ++i) {
          if (negative_pointers[i].pointer === this.cache[table][id]) {
            calls.push({
              'class': table,
              'function': 'create',
              'arguments': this.cache[table][id],
              'alias': (negative_pointers[i].alias = alias_to_table.length)
            });
            alias_to_table.push(table);
            break;
          }
        }
      }
    }
  }
};


cache.prototype.flush_get_updates = function(calls, alias_to_table) {
  for (var table in this.cache) {
    for (var id in this.cache[table]) {
      if (id > 0) {
        calls.push({
          'alias': alias_to_table.length,
          'class': table,
          'function': 'update',
          'arguments': rocket.extend(
            rocket.object(
              table + '_id',
              id
            ),
            this.cache[table][id]
          )
        });
        alias_to_table.push(table);
      }
    }
  }
};


cache.prototype.flush_get_updates_from_negative_pointers = function(calls, alias_to_table, negative_pointers) {
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
            (negative_pointers[j].value === +pointer.id)
          ) {
            id = '=' + negative_pointers[j].alias + '.' + pointer.table + '_id';
            break;
          }
        }
      }
      for (var j = 0; negative_pointers[j]; ++j) {
        if (
          (negative_pointers[j].self) &&
          (negative_pointers[j].value === pointer.value)
        ) {
          var value = '=' + negative_pointers[j].alias + '.' + pointer.table + '_id';
          break;
        }
      }
      calls.push({
        'alias': alias_to_table.length,
        'class': pointer.table,
        'function': 'update',
        'arguments': rocket.extend(
          rocket.object(pointer.table + '_id', id),
          rocket.object(pointer.column, value)
        )
      });
      alias_to_table.push(pointer.table);
    }
  }
};


cache.prototype.flush_handle_result = function(result, alias_to_table, negative_pointers, unresolved_negative_pointers) {
  for (var alias in result) {
    if (!(alias_to_table[alias] in cache.cache)) {
      cache.cache[alias_to_table[alias]] = {};
    }
    cache.cache[alias_to_table[alias]][result[alias][alias_to_table[alias] + '_id']] = result[alias];
  }
};

