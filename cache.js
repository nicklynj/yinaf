


var cache = function() {
  state.apply(this, arguments);
  this.cache = {};
};
rocket.inherits(cache, state);


cache.last_insert_id_ = 0;


cache.cache = {};


cache.prototype.load = function(data) {
  cache.cache = data;
};


cache.prototype.create = function(table, attributes) {
  return this.update(table, rocket.extend(
    rocket.object(table + '_id', --cache.last_insert_id_), 
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
    return this.cache_read_results_(caches, table, attributes[table + '_id']);
  } else {
    var matches = {};
    var mismatches = {};
    for (var i = caches.length - 1; i > -1; --i) {
      this.cache_read_helper_(matches, mismatches, caches[i], table, attributes);
    }
    var ids = [];
    for (var id in matches) {
      if (rocket.isEmpty(matches[id])) {
        ids.push(id);
      }
    }
    return this.cache_read_results_(caches, table, ids);
  }
};


cache.prototype.cache_read_helper_ = function(matches, mismatches, cache, table, attributes) {
  if (table in cache) {
    for (var id in cache[table]) {
      if (!(id in mismatches)) {
        if (!(id in matches)) {
          matches[id] = rocket.clone(attributes);
        }
        for (var column in matches[id]) {
          if (column in cache[table][id]) {
            var match = false;
            for (var i = 0; i in matches[id][column]; ++i) {
              if (matches[id][column][i] == cache[table][id][column]) {
                match = true;
              }
            }
            if (match) {
              delete matches[id][column];
            } else {
              delete matches[id];
              mismatches[id] = true;
              break;
            }
          }
        }
      }
    }
  }
};


cache.prototype.cache_read_results_ = function(caches, table, ids) {
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
  return this.cache_update_(this.cache, table, attributes);
};


cache.prototype.cache_update_ = function(target, table, attributes) {
  var id = attributes[table + '_id'];
  if (!(table in target)) {
    target[table] = {};
  }
  if (!(id in target[table])) {
    target[table][id] = {};
  }
  rocket.extend(target[table][id], attributes);
  return this.get(table, id);
};


cache.prototype.extend = function(table, attributes) {
  return this.cache_update_(cache.cache, table, attributes);
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


cache.prototype.diff = function() {
  this.cache_flush_remove_unnecessary_updates_();
  return rocket.isEmpty(this.cache) ? null : this.cache;
};


cache.prototype.flush = function(callback) {
  this.cache_flush_remove_unnecessary_updates_();
  var negative_pointers = this.cache_flush_remove_negative_pointers_();
  this.cache_flush_move_unresolved_negative_pointer_rows_back_(negative_pointers);
  var alias_to_table = [];
  var calls = [];
  this.cache_flush_get_inserts_(calls, alias_to_table, negative_pointers);
  this.cache_flush_get_updates_(calls, alias_to_table);
  this.cache_flush_get_updates_from_negative_pointers_(calls, alias_to_table, negative_pointers);
  this.cache_flush_collapse_updates_(calls);
  for (var table in this.cache) {
    delete this.cache[table];
  }
  if (calls.length) {
    var self = this;
    api('api', 'multiple', calls, function(result) {
      self.cache_flush_handle_result_(result, alias_to_table, negative_pointers);
      callback.call(self);
    });
  } else {
    callback.call(this);
  }
};


cache.prototype.cache_flush_remove_unnecessary_updates_ = function() {
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


cache.prototype.cache_flush_remove_negative_pointers_ = function() {
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


cache.prototype.cache_flush_move_unresolved_negative_pointer_rows_back_ = function(negative_pointers) {
  for (var i = 0; negative_pointers[i]; ++i) {
    var pointer = negative_pointers[i];
    if (!(pointer.self)) {
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
      if (!(match)) {
        this.cache_flush_replace_negative_pointer_(negative_pointers, pointer.table, pointer.id);
        this.get_previous_layer().update(pointer.table, this.cache[pointer.table][pointer.id]);
        delete this.cache[pointer.table][pointer.id];
        return this.cache_flush_move_unresolved_negative_pointer_rows_back_(negative_pointers);
      }
    }
  }
};


cache.prototype.cache_flush_replace_negative_pointer_ = function(negative_pointers, table, id) {
  for (var i = 0; negative_pointers[i]; ++i) {
    if (
      (negative_pointers[i].table === table) &&
      (negative_pointers[i].id === id)
    ) {
      this.cache[table][id][negative_pointers[i].column] = negative_pointers[i].value;
      negative_pointers.splice(i--, 1);
    }
  }
};


cache.prototype.cache_flush_get_inserts_ = function(calls, alias_to_table, negative_pointers) {
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


cache.prototype.cache_flush_get_updates_ = function(calls, alias_to_table) {
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


cache.prototype.cache_flush_get_updates_from_negative_pointers_ = function(calls, alias_to_table, negative_pointers) {
  for (var i = 0; negative_pointers[i]; ++i) {
    var pointer = negative_pointers[i];
    var alias_id;
    var alias_value;
    if (!(pointer.self)) {
      var id;
      if (pointer.id > 0) {
        id = pointer.id;
      } else {
        for (j = 0; negative_pointers[j]; ++j) {
          if (
            (negative_pointers[j].self) &&
            (negative_pointers[j].value === +pointer.id)
          ) {
            id = '=' + (alias_id = negative_pointers[j].alias) + '.' + pointer.table + '_id';
            break;
          }
        }
      }
      for (var j = 0; negative_pointers[j]; ++j) {
        if (
          (negative_pointers[j].self) &&
          (negative_pointers[j].value === pointer.value)
        ) {
          var value = '=' + (alias_value = negative_pointers[j].alias) + '.' + negative_pointers[j].table + '_id';
          break;
        }
      }
      if (
        (typeof alias_id === 'number') &&
        (typeof alias_value === 'number') &&
        (alias_id > alias_value)
      ) {
        calls[alias_id].arguments[pointer.column] = value;
      } else {
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
  }
};


cache.prototype.cache_flush_collapse_updates_ = function(calls) {
  var map = {};
  for (var i = 0; calls[i]; ++i) {
    if (calls[i]['function'] === 'update') {
      var cls = calls[i]['class'];
      if (!(cls in map)) {
        map[cls] = {};
      }
      if (calls[i].arguments[cls + '_id'] in map[cls]) {
        rocket.extend(map[cls][calls[i].arguments[cls + '_id']].arguments, calls[i].arguments);
        calls.splice(i--, 1);
      } else {
        map[cls][calls[i].arguments[cls + '_id']] = calls[i];
      }
    }
  }
};


cache.prototype.cache_flush_handle_result_ = function(result, alias_to_table) {
  for (var alias in result) {
    if (!(alias_to_table[alias] in cache.cache)) {
      cache.cache[alias_to_table[alias]] = {};
    }
    cache.cache[alias_to_table[alias]][result[alias][alias_to_table[alias] + '_id']] = result[alias];
  }
};


cache.prototype.propagate = function() {
  this.cache_propagate(this.cache, this.get_previous_layer().cache);
};


cache.prototype.cache_propagate = function(from, to) {
  for (var table in from) {
    if (!(table in to)) {
      to[table] = {};
    }
    for (var id in from[table]) {
      if (!(id in to[table])) {
        to[table][id] = {};
      }
      for (var column in from[table][id]) {
        to[table][id][column] = from[table][id][column];
      }
    }
    delete from[table];
  }
};
